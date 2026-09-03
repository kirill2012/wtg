<?php

namespace Tests\Feature;

use App\Models\Offer;
use App\Services\ReservationService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * Two bookings at the same time, on two real connections. Runs on DatabaseTruncation, not
 * RefreshDatabase: the transaction RefreshDatabase wraps every test in would turn both
 * "connections" into savepoints of one transaction and hide the locks under test.
 *
 * The orientation is the only one that tests our code rather than MySQL: the secondary
 * connection plays the other booking by hand, and the real ReservationService runs as is,
 * on the default connection, where the models live.
 *
 * @see RefreshDatabase
 */
class ReservationConcurrencyTest extends TestCase
{
    use DatabaseTruncation;

    protected function setUp(): void
    {
        parent::setUp();

        // Laravel caches one PDO per connection name; a second name is the only way to a
        // second socket. config/database.php does not know about the test.
        config(['database.connections.mysql_secondary' => config('database.connections.mysql')]);
    }

    protected function tearDown(): void
    {
        try {
            // Release the other booking's row lock first: the truncation below runs on the
            // default connection and would otherwise wait on it.
            $other = DB::connection('mysql_secondary');
            if ($other->transactionLevel() > 0) {
                $other->rollBack();
            }
            $other->disconnect();

            DB::statement('SET SESSION innodb_lock_wait_timeout = DEFAULT');

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

    public function test_a_booking_waits_on_the_offer_row_locked_by_another_booking(): void
    {
        $offer = Offer::factory()->create(['available_units' => 1]);

        // The other booking, mid-transaction: it holds the offer's row lock and has not
        // committed, exactly the window in which a second booking of the last unit arrives.
        $other = DB::connection('mysql_secondary');
        $other->beginTransaction();
        $other->table('offers')->where('id', $offer->id)->lockForUpdate()->first();

        // The waiting side is the default connection. Without this the test would sit out
        // the 50-second server default instead of failing.
        DB::statement('SET SESSION innodb_lock_wait_timeout = 1');

        try {
            app(ReservationService::class)->reserve($offer, $this->payload());
            $this->fail('The booking went through while another transaction held the offer row.');
        } catch (QueryException $e) {
            // MySQL's 1205 sits in errorInfo[1]; getCode() carries the SQLSTATE string.
            $this->assertSame(1205, $e->errorInfo[1], $e->getMessage());
        }

        $other->rollBack();

        $this->assertSame(0, $offer->fresh()->reserved_units);
        $this->assertDatabaseCount('reservations', 0);
    }

    public function test_a_reference_committed_by_another_connection_mid_flight_is_seen_despite_the_snapshot(): void
    {
        $offer = Offer::factory()->create();
        $winnersOffer = Offer::factory()->create();

        // Plays the other request on its own connection: it commits the same reference for
        // another offer right after our idempotency lookup came back empty. Our transaction's
        // snapshot predates that commit, so only a locking re-read can find the row.
        $raced = false;
        DB::listen(function (QueryExecuted $query) use (&$raced, $winnersOffer): void {
            if ($raced || ! str_starts_with($query->sql, 'select') || ! str_contains($query->sql, '`reservations`')) {
                return;
            }

            $raced = true;
            DB::connection('mysql_secondary')->table('reservations')->insert([
                'offer_id' => $winnersOffer->id,
                'client_reference' => 'web-order-9f782b1c',
                'customer_name' => 'Jane Smith',
                'customer_email' => 'jane@example.com',
                'price' => $winnersOffer->price,
                'currency' => $winnersOffer->currency,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        try {
            app(ReservationService::class)->reserve($offer, $this->payload());
            $this->fail('The same reference on another offer was accepted.');
        } catch (HttpException $e) {
            $this->assertSame(409, $e->getStatusCode());
        }

        $this->assertTrue($raced);
        $this->assertSame(0, $offer->fresh()->reserved_units);
        $this->assertDatabaseCount('reservations', 1);
    }

    /**
     * @return array{client_reference: string, customer_name: string, customer_email: string}
     */
    private function payload(): array
    {
        return [
            'client_reference' => 'web-order-9f782b1c',
            'customer_name' => 'John Smith',
            'customer_email' => 'john@example.com',
        ];
    }
}
