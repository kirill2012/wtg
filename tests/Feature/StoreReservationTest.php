<?php

namespace Tests\Feature;

use App\Models\Offer;
use App\Models\Reservation;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class StoreReservationTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_books_one_unit_and_returns_the_reservation(): void
    {
        $this->travelTo('2026-09-03 12:00:00');
        $offer = Offer::factory()->create(['price' => 72500, 'currency' => 'EUR', 'available_units' => 2]);

        $response = $this->postJson(route('offers.reservations.store', $offer), $this->payload());

        $reservation = Reservation::query()->sole();
        $response
            ->assertCreated()
            ->assertExactJson(['data' => [
                'id' => $reservation->id,
                'offer_id' => $offer->id,
                'client_reference' => 'web-order-9f782b1c',
                'customer_name' => 'John Smith',
                'customer_email' => 'john@example.com',
                'price' => 72500,
                'currency' => 'EUR',
                'created_at' => '2026-09-03T12:00:00Z',
            ]]);

        $offer->refresh();
        $this->assertSame(1, $offer->reserved_units);
        $this->assertSame(1, $offer->free_units);
        $this->assertSame(2, $offer->available_units, 'The supplier\'s column must stay untouched');
    }

    public function test_the_reservation_keeps_the_price_the_offer_had_at_booking_time(): void
    {
        $offer = Offer::factory()->create(['price' => 72500, 'currency' => 'EUR']);

        $this->postJson(route('offers.reservations.store', $offer), $this->payload())->assertCreated();
        $offer->update(['price' => 99000, 'currency' => 'USD']);

        $this->assertDatabaseHas('reservations', ['offer_id' => $offer->id, 'price' => 72500, 'currency' => 'EUR']);
    }

    public function test_the_last_unit_can_be_booked_only_once(): void
    {
        $offer = Offer::factory()->create(['available_units' => 1]);

        $this->postJson(route('offers.reservations.store', $offer), $this->payload())->assertCreated();
        $this->postJson(route('offers.reservations.store', $offer), $this->payload(['client_reference' => 'web-order-other']))
            ->assertConflict()
            ->assertExactJson(['message' => 'The offer is sold out.']);

        $this->assertSame(1, $offer->fresh()->reserved_units);
        $this->assertDatabaseCount('reservations', 1);
    }

    public function test_resending_the_same_reference_returns_the_same_reservation_without_taking_another_unit(): void
    {
        $offer = Offer::factory()->create(['available_units' => 2]);

        $first = $this->postJson(route('offers.reservations.store', $offer), $this->payload())->assertCreated();
        $second = $this->postJson(route('offers.reservations.store', $offer), $this->payload())->assertOk();

        $this->assertSame($first->json('data'), $second->json('data'));
        $this->assertSame(1, $offer->fresh()->reserved_units);
        $this->assertDatabaseCount('reservations', 1);
    }

    public function test_a_resend_is_honoured_even_after_the_offer_expired_or_sold_out(): void
    {
        $offer = Offer::factory()->create(['available_units' => 1]);

        $first = $this->postJson(route('offers.reservations.store', $offer), $this->payload())->assertCreated();
        $offer->update(['expires_at' => now()->subMinute()]);

        $this->postJson(route('offers.reservations.store', $offer), $this->payload())
            ->assertOk()
            ->assertJsonPath('data.id', $first->json('data.id'));
    }

    public function test_the_same_reference_on_another_offer_is_a_conflict(): void
    {
        $offer = Offer::factory()->create();
        $other = Offer::factory()->create();

        $this->postJson(route('offers.reservations.store', $offer), $this->payload())->assertCreated();
        $this->postJson(route('offers.reservations.store', $other), $this->payload())
            ->assertConflict()
            ->assertExactJson(['message' => 'This client_reference already belongs to a reservation of another offer.']);

        $this->assertSame(0, $other->fresh()->reserved_units);
        $this->assertDatabaseCount('reservations', 1);
    }

    public function test_an_expired_offer_cannot_be_booked(): void
    {
        $offer = Offer::factory()->expired()->create();

        $this->postJson(route('offers.reservations.store', $offer), $this->payload())
            ->assertConflict()
            ->assertExactJson(['message' => 'The offer has expired.']);

        $this->assertSame(0, $offer->fresh()->reserved_units);
        $this->assertDatabaseCount('reservations', 0);
    }

    public function test_an_offer_expiring_this_very_second_is_already_closed(): void
    {
        $this->freezeSecond();
        $offer = Offer::factory()->create(['expires_at' => now()]);

        $this->postJson(route('offers.reservations.store', $offer), $this->payload())->assertConflict();
    }

    public function test_an_offer_whose_supply_was_lowered_under_its_reservations_is_sold_out_not_negative(): void
    {
        // A later import may publish fewer units than are already reserved; the published
        // remainder is clamped at zero and nothing more can be booked.
        $offer = Offer::factory()->create(['available_units' => 1, 'reserved_units' => 3]);

        $this->postJson(route('offers.reservations.store', $offer), $this->payload())
            ->assertConflict()
            ->assertExactJson(['message' => 'The offer is sold out.']);

        $this->assertSame(0, $offer->fresh()->free_units);
        $this->assertSame(3, $offer->fresh()->reserved_units);
    }

    public function test_a_reservation_already_in_the_database_is_returned_without_touching_the_counter(): void
    {
        $offer = Offer::factory()->create();
        $existing = Reservation::factory()->for($offer)->create(['client_reference' => 'web-order-9f782b1c']);

        $this->postJson(route('offers.reservations.store', $offer), $this->payload())
            ->assertOk()
            ->assertJsonPath('data.id', $existing->id);

        $this->assertSame(0, $offer->fresh()->reserved_units);
        $this->assertDatabaseCount('reservations', 1);
    }

    public function test_losing_the_insert_race_to_a_request_for_the_same_offer_returns_the_winners_reservation(): void
    {
        $offer = Offer::factory()->create();

        // Plays the concurrent request: its row appears after our idempotency lookup came
        // back empty and before our insert, so the insert hits the unique key.
        $winner = null;
        DB::listen(function (QueryExecuted $query) use (&$winner, $offer): void {
            if ($winner !== null || ! str_starts_with($query->sql, 'select') || ! str_contains($query->sql, '`reservations`')) {
                return;
            }

            $winner = Reservation::factory()->for($offer)->create(['client_reference' => 'web-order-9f782b1c']);
        });

        $response = $this->postJson(route('offers.reservations.store', $offer), $this->payload());

        $this->assertNotNull($winner, 'The race never happened: no lookup on reservations was observed');
        $response->assertOk()->assertJsonPath('data.id', $winner->id);
        // The simulated winner does not move the counter; zero proves our path did not take
        // a unit on top of the reservation it returned.
        $this->assertSame(0, $offer->fresh()->reserved_units);
        $this->assertDatabaseCount('reservations', 1);
    }

    public function test_losing_the_insert_race_to_a_request_for_another_offer_is_a_conflict(): void
    {
        $offer = Offer::factory()->create();
        $other = Offer::factory()->create();

        $raced = false;
        DB::listen(function (QueryExecuted $query) use (&$raced, $other): void {
            if ($raced || ! str_starts_with($query->sql, 'select') || ! str_contains($query->sql, '`reservations`')) {
                return;
            }

            $raced = true;
            Reservation::factory()->for($other)->create(['client_reference' => 'web-order-9f782b1c']);
        });

        $response = $this->postJson(route('offers.reservations.store', $offer), $this->payload());

        // No row count here: on one connection the simulated winner sits inside our own
        // transaction and is rolled back with the 409. ReservationConcurrencyTest covers the
        // real two-connection case.
        $this->assertTrue($raced);
        $response
            ->assertConflict()
            ->assertExactJson(['message' => 'This client_reference already belongs to a reservation of another offer.']);
        $this->assertSame(0, $offer->fresh()->reserved_units);
    }

    public function test_it_returns_404_for_an_unknown_offer(): void
    {
        $this->postJson(route('offers.reservations.store', 999), $this->payload())
            ->assertNotFound()
            ->assertExactJson(['message' => 'Not Found.']);

        $this->assertDatabaseCount('reservations', 0);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @param  list<string>  $errors
     */
    #[DataProvider('invalidRequests')]
    public function test_it_rejects_an_invalid_request(array $overrides, array $errors): void
    {
        $offer = Offer::factory()->create();

        $this->postJson(route('offers.reservations.store', $offer), $this->payload($overrides))
            ->assertUnprocessable()
            ->assertJsonValidationErrors($errors);

        $this->assertDatabaseCount('reservations', 0);
    }

    /**
     * @return array<string, array{array<string, mixed>, list<string>}>
     */
    public static function invalidRequests(): array
    {
        return [
            'nothing at all' => [
                ['client_reference' => null, 'customer_name' => null, 'customer_email' => null],
                ['client_reference', 'customer_name', 'customer_email'],
            ],
            'reference longer than the column' => [['client_reference' => str_repeat('x', 256)], ['client_reference']],
            'name that is not a string' => [['customer_name' => ['John']], ['customer_name']],
            'malformed email' => [['customer_email' => 'john at example.com'], ['customer_email']],
        ];
    }

    /**
     * The request body from the task description, with overrides.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_replace([
            'client_reference' => 'web-order-9f782b1c',
            'customer_name' => 'John Smith',
            'customer_email' => 'john@example.com',
        ], $overrides);
    }
}
