<?php

namespace Tests\Feature;

use App\Enums\ImportStatus;
use App\Jobs\ProcessImportJob;
use App\Models\Import;
use App\Models\Offer;
use App\Models\Property;
use App\Models\Supplier;
use Database\Seeders\SupplierSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Two workers processing imports of one supplier at the same time. Runs on
 * DatabaseTruncation, not RefreshDatabase: the races below are about transaction snapshots
 * and row locks, and the transaction RefreshDatabase wraps every test in would hide them.
 *
 * @see RefreshDatabase
 */
class ImportConcurrencyTest extends TestCase
{
    use DatabaseTruncation;

    protected function setUp(): void
    {
        parent::setUp();

        // Laravel caches one PDO per connection name; a second name is the only way to a
        // second socket. config/database.php does not know about the test.
        config(['database.connections.mysql_secondary' => config('database.connections.mysql')]);

        $this->seed(SupplierSeeder::class);
    }

    protected function tearDown(): void
    {
        try {
            DB::connection('mysql_secondary')->disconnect();

            // DatabaseTruncation cleans before a test, not after; rows left here would leak
            // into the RefreshDatabase tests that run next and break their row counts.
            Schema::withoutForeignKeyConstraints(function (): void {
                foreach (['reservations', 'offers', 'imports', 'properties', 'suppliers'] as $table) {
                    DB::table($table)->truncate();
                }
            });
        } finally {
            parent::tearDown();
        }
    }

    public function test_a_property_created_by_another_worker_mid_flight_is_reused_not_duplicated(): void
    {
        $supplier = Supplier::query()->where('slug', 'supplier-a')->sole();
        $import = Import::factory()->for($supplier)->create([
            'payload' => [$this->offer('offer-a-10001')],
            'total_offers' => 1,
        ]);

        // Plays the other worker on its own connection: it commits the property after our
        // lookup came back empty and before our insert, so our insert hits the unique key.
        $raced = false;
        DB::listen(function (QueryExecuted $query) use (&$raced): void {
            if ($raced || ! str_starts_with($query->sql, 'select') || ! str_contains($query->sql, '`properties`')) {
                return;
            }

            $raced = true;
            DB::connection('mysql_secondary')->table('properties')->insert([
                'code' => 'BCN-0001',
                'name' => 'Created by the other worker',
                'city' => 'Barcelona',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        ProcessImportJob::dispatchSync($import);

        $this->assertTrue($raced);
        $this->assertSame(ImportStatus::Completed, $import->fresh()->status);
        $this->assertDatabaseCount('properties', 1);
        $this->assertDatabaseHas('properties', ['code' => 'BCN-0001', 'name' => 'Created by the other worker']);
        $this->assertSame('BCN-0001', Offer::query()->sole()->property->code);
    }

    public function test_another_worker_inserting_a_different_new_offer_of_the_same_supplier_is_not_blocked(): void
    {
        $supplier = Supplier::query()->where('slug', 'supplier-a')->sole();
        $import = Import::factory()->for($supplier)->create([
            'payload' => [$this->offer('offer-a-10001')],
            'total_offers' => 1,
        ]);

        // Parents of the other worker's row, committed up front: an uncommitted parent would
        // make its insert wait on the foreign key check, which is not the lock under test.
        $property = Property::factory()->create();
        $otherImport = Import::factory()->for($supplier)->create();

        $other = DB::connection('mysql_secondary');
        // A gap lock would otherwise hold the insert for the 50-second default.
        $other->statement('SET SESSION innodb_lock_wait_timeout = 1');

        // Plays the other worker: while our offer's lookup has come back empty and its insert
        // is still ahead, it inserts a different new offer of the same supplier — a key in
        // the same index range. A locking lookup would have gap-locked that range.
        $raced = false;
        $blocked = null;
        DB::listen(function (QueryExecuted $query) use (&$raced, &$blocked, $other, $supplier, $property, $otherImport): void {
            if ($raced || ! str_starts_with($query->sql, 'select') || ! str_contains($query->sql, '`offers`')) {
                return;
            }

            $raced = true;

            try {
                $other->table('offers')->insert([
                    'supplier_id' => $supplier->id,
                    'property_id' => $property->id,
                    'import_id' => $otherImport->id,
                    'external_id' => 'offer-a-10002',
                    'sent_at' => $otherImport->sent_at,
                    'check_in' => '2026-10-10',
                    'check_out' => '2026-10-15',
                    'max_guests' => 2,
                    'price' => 61000,
                    'currency' => 'EUR',
                    'available_units' => 1,
                    'expires_at' => '2026-09-10 23:59:59',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } catch (QueryException $e) {
                $blocked = $e;
            }
        });

        ProcessImportJob::dispatchSync($import);

        $this->assertTrue($raced);
        $this->assertNull($blocked, 'The other worker\'s insert waited on a lock: '.$blocked?->getMessage());
        $this->assertSame(ImportStatus::Completed, $import->fresh()->status);
        $this->assertDatabaseCount('offers', 2);
    }

    public function test_an_offer_committed_by_another_worker_mid_flight_is_updated_not_lost(): void
    {
        $supplier = Supplier::query()->where('slug', 'supplier-a')->sole();
        $import = Import::factory()->for($supplier)->create([
            'sent_at' => '2026-09-01 10:00:00',
            'payload' => [$this->offer('offer-a-10001')],
            'total_offers' => 1,
        ]);

        // Committed up front, or the other worker's insert would wait on the foreign key.
        $property = Property::factory()->create();
        $otherImport = Import::factory()->for($supplier)->create(['sent_at' => '2026-09-01 09:00:00']);

        // Plays the other worker: it commits our offer right after the plain lookup came back
        // empty, and that lookup fixed this transaction's snapshot. Only a locking re-read
        // sees the row afterwards; a plain one dies with ModelNotFoundException.
        $raced = false;
        DB::listen(function (QueryExecuted $query) use (&$raced, $supplier, $property, $otherImport): void {
            if ($raced || ! str_starts_with($query->sql, 'select') || ! str_contains($query->sql, '`offers`')) {
                return;
            }

            $raced = true;
            DB::connection('mysql_secondary')->table('offers')->insert([
                'supplier_id' => $supplier->id,
                'property_id' => $property->id,
                'import_id' => $otherImport->id,
                'external_id' => 'offer-a-10001',
                'sent_at' => $otherImport->sent_at,
                'check_in' => '2026-10-01',
                'check_out' => '2026-10-05',
                'max_guests' => 2,
                'price' => 61000,
                'currency' => 'EUR',
                'available_units' => 1,
                'reserved_units' => 1,
                'expires_at' => '2026-09-10 23:59:59',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        ProcessImportJob::dispatchSync($import);

        $this->assertTrue($raced);
        $this->assertSame(ImportStatus::Completed, $import->fresh()->status);
        $this->assertDatabaseCount('offers', 1);

        // Our payload won, and reserved_units survived: an import never writes it.
        $offer = Offer::query()->sole();
        $this->assertSame(72500, $offer->price);
        $this->assertSame('2026-10-10', $offer->check_in->toDateString());
        $this->assertTrue($offer->import->is($import));
        $this->assertSame('BCN-0001', $offer->property->code);
        $this->assertSame(1, $offer->reserved_units);
    }

    /**
     * One offer from the task description.
     *
     * @return array<string, mixed>
     */
    private function offer(string $externalId): array
    {
        return [
            'external_id' => $externalId,
            'property' => ['code' => 'BCN-0001', 'name' => 'Apartment near Sagrada Familia', 'City' => 'Barcelona'],
            'check_in' => '2026-10-10',
            'check_out' => '2026-10-15',
            'max_guests' => 4,
            'price' => 72500,
            'currency' => 'EUR',
            'available_units' => 2,
            'expires_at' => '2026-09-10T23:59:59Z',
        ];
    }
}
