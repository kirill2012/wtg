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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ProcessImportJobTest extends TestCase
{
    use RefreshDatabase;

    private Supplier $supplier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(SupplierSeeder::class);
        $this->supplier = Supplier::query()->where('slug', 'supplier-a')->sole();
    }

    public function test_it_creates_properties_and_offers_from_the_payload(): void
    {
        $import = $this->import([
            $this->offer(),
            $this->offer([
                'external_id' => 'offer-a-10002',
                'property' => ['code' => 'BCN-0002', 'name' => 'Loft in Gràcia', 'City' => 'Barcelona'],
                'price' => 61000,
            ]),
        ]);

        ProcessImportJob::dispatchSync($import);

        $import->refresh();
        $this->assertSame(ImportStatus::Completed, $import->status);
        $this->assertSame(2, $import->processed_offers);
        $this->assertNotNull($import->completed_at);
        $this->assertNull($import->error);

        $this->assertDatabaseCount('properties', 2);
        $this->assertDatabaseHas('properties', [
            'code' => 'BCN-0001',
            'name' => 'Apartment near Sagrada Familia',
            'city' => 'Barcelona',
        ]);
        $this->assertDatabaseCount('offers', 2);

        $offer = Offer::query()->where('external_id', 'offer-a-10001')->sole();
        $this->assertTrue($offer->supplier->is($this->supplier));
        $this->assertSame('BCN-0001', $offer->property->code);
        $this->assertTrue($offer->import->is($import));
        $this->assertTrue($offer->sent_at->equalTo($import->sent_at));
        $this->assertSame('2026-10-10', $offer->check_in->toDateString());
        $this->assertSame('2026-10-15', $offer->check_out->toDateString());
        $this->assertSame(4, $offer->max_guests);
        $this->assertSame(72500, $offer->price);
        $this->assertSame('EUR', $offer->currency);
        $this->assertSame(2, $offer->available_units);
        $this->assertSame(0, $offer->reserved_units);
        $this->assertSame('2026-09-10 23:59:59', $offer->expires_at->toDateTimeString());
    }

    public function test_it_updates_an_offer_that_arrived_earlier_in_another_import(): void
    {
        ProcessImportJob::dispatchSync($this->import([$this->offer()], ['sent_at' => '2026-09-01 09:00:00']));
        $offer = Offer::query()->sole();

        $later = $this->import([
            $this->offer([
                'property' => ['code' => 'BCN-0002', 'name' => 'Loft in Gràcia', 'City' => 'Barcelona'],
                'check_in' => '2026-10-11',
                'check_out' => '2026-10-16',
                'max_guests' => 2,
                'price' => 69900,
                'available_units' => 1,
                'expires_at' => '2026-09-12T00:00:00Z',
            ]),
        ], ['sent_at' => '2026-09-01 10:00:00']);

        ProcessImportJob::dispatchSync($later);

        $this->assertDatabaseCount('offers', 1);
        $offer->refresh();
        $this->assertSame('BCN-0002', $offer->property->code);
        $this->assertSame('2026-10-11', $offer->check_in->toDateString());
        $this->assertSame('2026-10-16', $offer->check_out->toDateString());
        $this->assertSame(2, $offer->max_guests);
        $this->assertSame(69900, $offer->price);
        $this->assertSame(1, $offer->available_units);
        $this->assertSame('2026-09-12 00:00:00', $offer->expires_at->toDateTimeString());
        $this->assertTrue($offer->import->is($later));
        $this->assertSame('2026-09-01 10:00:00', $offer->sent_at->toDateTimeString());
    }

    public function test_an_older_import_processed_later_does_not_overwrite_a_newer_offer(): void
    {
        $offer = Offer::factory()->for($this->supplier)->create([
            'external_id' => 'offer-a-10001',
            'price' => 80000,
            'sent_at' => '2026-09-01 10:00:00',
        ]);
        $stale = $this->import([$this->offer(['price' => 72500])], ['sent_at' => '2026-09-01 09:00:00']);

        ProcessImportJob::dispatchSync($stale);

        $offer->refresh();
        $this->assertSame(80000, $offer->price);
        $this->assertFalse($offer->import->is($stale));
        $this->assertSame('2026-09-01 10:00:00', $offer->sent_at->toDateTimeString());

        $stale->refresh();
        $this->assertSame(ImportStatus::Completed, $stale->status);
        $this->assertSame(1, $stale->processed_offers);
    }

    public function test_an_import_with_the_same_sent_at_still_updates_the_offer(): void
    {
        $offer = Offer::factory()->for($this->supplier)->create([
            'external_id' => 'offer-a-10001',
            'price' => 80000,
            'sent_at' => '2026-09-01 10:00:00',
        ]);
        $import = $this->import([$this->offer(['price' => 72500])], ['sent_at' => '2026-09-01 10:00:00']);

        ProcessImportJob::dispatchSync($import);

        $offer->refresh();
        $this->assertSame(72500, $offer->price);
        $this->assertTrue($offer->import->is($import));
    }

    public function test_expires_at_is_stored_as_the_utc_moment(): void
    {
        ProcessImportJob::dispatchSync($this->import([$this->offer(['expires_at' => '2026-09-11T01:59:59+02:00'])]));

        $this->assertDatabaseHas('offers', ['expires_at' => '2026-09-10 23:59:59']);
    }

    public function test_the_currency_is_stored_in_upper_case(): void
    {
        ProcessImportJob::dispatchSync($this->import([$this->offer(['currency' => 'eur'])]));

        $this->assertDatabaseHas('offers', ['currency' => 'EUR']);
    }

    public function test_an_existing_property_is_not_rewritten(): void
    {
        Property::factory()->create(['code' => 'BCN-0001', 'name' => 'Original name', 'city' => 'Girona']);

        ProcessImportJob::dispatchSync($this->import([$this->offer()]));

        $this->assertDatabaseCount('properties', 1);
        $this->assertDatabaseHas('properties', ['code' => 'BCN-0001', 'name' => 'Original name', 'city' => 'Girona']);
    }

    public function test_one_property_code_from_both_suppliers_gives_one_property_and_two_offers(): void
    {
        $supplierB = Supplier::query()->where('slug', 'supplier-b')->sole();

        ProcessImportJob::dispatchSync($this->import([$this->offer()]));
        ProcessImportJob::dispatchSync($this->import([
            $this->offer([
                'external_id' => 'offer-b-1',
                'property' => ['code' => 'BCN-0001', 'name' => 'Same flat, other listing', 'City' => 'Barcelona'],
                'price' => 70000,
            ]),
        ], supplier: $supplierB));

        $property = Property::query()->sole();
        $this->assertSame('Apartment near Sagrada Familia', $property->name);
        $this->assertCount(2, $property->offers()->get());
        $this->assertSame(2, Offer::query()->distinct()->count('supplier_id'));
    }

    public function test_the_same_external_id_from_different_suppliers_are_different_offers(): void
    {
        $supplierB = Supplier::query()->where('slug', 'supplier-b')->sole();

        ProcessImportJob::dispatchSync($this->import([$this->offer(['price' => 72500])]));
        ProcessImportJob::dispatchSync($this->import([$this->offer(['price' => 65000])], supplier: $supplierB));

        $this->assertDatabaseCount('offers', 2);
        $this->assertDatabaseHas('offers', ['supplier_id' => $this->supplier->id, 'external_id' => 'offer-a-10001', 'price' => 72500]);
        $this->assertDatabaseHas('offers', ['supplier_id' => $supplierB->id, 'external_id' => 'offer-a-10001', 'price' => 65000]);
    }

    public function test_reserved_units_survive_a_re_import(): void
    {
        $offer = Offer::factory()->for($this->supplier)->create([
            'external_id' => 'offer-a-10001',
            'available_units' => 2,
            'reserved_units' => 2,
            'sent_at' => '2026-09-01 09:00:00',
        ]);

        ProcessImportJob::dispatchSync($this->import([$this->offer(['available_units' => 5])]));

        $offer->refresh();
        $this->assertSame(5, $offer->available_units);
        $this->assertSame(2, $offer->reserved_units);
        $this->assertSame(3, $offer->free_units);
    }

    public function test_losing_the_insert_race_to_another_worker_continues_with_that_row(): void
    {
        $import = $this->import([$this->offer(['price' => 69900])]);

        // Plays the other worker: the row appears after the job's lookup found nothing and
        // before its insert, so the insert hits the unique key.
        $raced = false;
        DB::listen(function (QueryExecuted $query) use (&$raced): void {
            $isOfferLookup = str_starts_with($query->sql, 'select') && str_contains($query->sql, '`offers`');

            if ($raced || ! $isOfferLookup) {
                return;
            }

            $raced = true;
            Offer::factory()->for($this->supplier)->create([
                'external_id' => 'offer-a-10001',
                'price' => 10,
                'sent_at' => '2026-09-01 09:00:00',
            ]);
        });

        ProcessImportJob::dispatchSync($import);

        $this->assertTrue($raced);
        $this->assertDatabaseCount('offers', 1);
        $offer = Offer::query()->sole();
        $this->assertSame(69900, $offer->price);
        $this->assertTrue($offer->import->is($import));
        $this->assertSame(ImportStatus::Completed, $import->refresh()->status);
    }

    public function test_a_failing_offer_marks_the_import_failed_and_keeps_the_offers_already_written(): void
    {
        $import = $this->import([
            $this->offer(),
            // Bypasses the request validation on purpose: the unsigned column rejects it.
            $this->offer(['external_id' => 'offer-a-10002', 'price' => -1]),
        ]);

        try {
            ProcessImportJob::dispatchSync($import);
            $this->fail('The job should have failed on the second offer.');
        } catch (QueryException) {
        }

        $import->refresh();
        $this->assertSame(ImportStatus::Failed, $import->status);
        $this->assertSame(1, $import->processed_offers);
        // The field is public: the driver's message, and nothing of the statement, the
        // bindings or the connection behind it.
        $this->assertStringContainsString('Out of range', (string) $import->error);
        $this->assertStringNotContainsString('Connection:', (string) $import->error);
        $this->assertStringNotContainsString('insert into', (string) $import->error);
        $this->assertNotNull($import->completed_at);
        $this->assertDatabaseHas('offers', ['external_id' => 'offer-a-10001']);
        $this->assertDatabaseMissing('offers', ['external_id' => 'offer-a-10002']);
    }

    public function test_a_new_attempt_starts_the_counter_and_the_error_from_scratch(): void
    {
        $import = $this->import([$this->offer()], [
            'status' => ImportStatus::Failed,
            'processed_offers' => 7,
            'error' => 'Previous attempt blew up',
            'completed_at' => '2026-09-01 10:05:00',
        ]);

        ProcessImportJob::dispatchSync($import);

        $import->refresh();
        $this->assertSame(ImportStatus::Completed, $import->status);
        $this->assertSame(1, $import->processed_offers);
        $this->assertNull($import->error);
        $this->assertTrue($import->completed_at->isAfter('2026-09-01 10:05:00'));
    }

    public function test_re_running_the_job_is_idempotent(): void
    {
        $import = $this->import([$this->offer(), $this->offer(['external_id' => 'offer-a-10002'])]);

        ProcessImportJob::dispatchSync($import);
        ProcessImportJob::dispatchSync($import);

        $this->assertDatabaseCount('offers', 2);
        $this->assertDatabaseCount('properties', 1);
        $import->refresh();
        $this->assertSame(2, $import->total_offers);
        $this->assertSame(2, $import->processed_offers);
    }

    /**
     * An import row as ImportService::accept() would have stored it.
     *
     * @param  list<array<string, mixed>>  $offers
     * @param  array<string, mixed>  $attributes
     */
    private function import(array $offers, array $attributes = [], ?Supplier $supplier = null): Import
    {
        return Import::factory()->for($supplier ?? $this->supplier)->create([
            'sent_at' => '2026-09-01 10:00:00',
            'payload' => $offers,
            'total_offers' => count($offers),
            ...$attributes,
        ]);
    }

    /**
     * One offer from the task description, with optional overrides.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function offer(array $overrides = []): array
    {
        return array_replace([
            'external_id' => 'offer-a-10001',
            'property' => [
                'code' => 'BCN-0001',
                'name' => 'Apartment near Sagrada Familia',
                'City' => 'Barcelona',
            ],
            'check_in' => '2026-10-10',
            'check_out' => '2026-10-15',
            'max_guests' => 4,
            'price' => 72500,
            'currency' => 'EUR',
            'available_units' => 2,
            'expires_at' => '2026-09-10T23:59:59Z',
        ], $overrides);
    }
}
