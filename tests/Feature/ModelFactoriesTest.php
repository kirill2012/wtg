<?php

namespace Tests\Feature;

use App\Models\Import;
use App\Models\Offer;
use App\Models\Reservation;
use App\Models\Supplier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The factories carry wiring that tests elsewhere rely on silently: an offer's import
 * must belong to the offer's supplier, a reservation snapshots its offer's price.
 */
class ModelFactoriesTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_offer_belongs_to_an_import_of_its_own_supplier(): void
    {
        $offer = Offer::factory()->create();

        $this->assertTrue($offer->import->supplier->is($offer->supplier));
    }

    public function test_for_import_takes_the_supplier_and_sent_at_from_the_import(): void
    {
        $sentAt = Carbon::parse('2026-09-01 10:00:00');
        $import = Import::factory()->for(Supplier::factory())->create(['sent_at' => $sentAt]);

        $offer = Offer::factory()->forImport($import)->create();

        $this->assertTrue($offer->import->is($import));
        $this->assertTrue($offer->supplier->is($import->supplier));
        $this->assertTrue($offer->sent_at->equalTo($sentAt));
    }

    public function test_sold_out_leaves_no_free_units(): void
    {
        $offer = Offer::factory()->soldOut(3)->create();

        $this->assertSame(3, $offer->available_units);
        $this->assertSame(3, $offer->reserved_units);
        $this->assertSame(0, $offer->free_units);
    }

    public function test_a_reservation_snapshots_the_offer_price_and_currency(): void
    {
        $offer = Offer::factory()->create(['price' => 72500, 'currency' => 'EUR']);

        $reservation = Reservation::factory()->for($offer)->create();

        $this->assertSame(72500, $reservation->price);
        $this->assertSame('EUR', $reservation->currency);
    }

    public function test_a_completed_import_reports_all_offers_processed(): void
    {
        $import = Import::factory()->completed(20)->create();

        $this->assertSame(20, $import->total_offers);
        $this->assertSame(20, $import->processed_offers);
        $this->assertNotNull($import->completed_at);
    }
}
