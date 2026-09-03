<?php

namespace Tests\Unit;

use App\Models\Offer;
use PHPUnit\Framework\TestCase;

class OfferFreeUnitsTest extends TestCase
{
    public function test_free_units_is_what_the_supplier_published_minus_what_is_reserved(): void
    {
        $offer = (new Offer)->forceFill(['available_units' => 3, 'reserved_units' => 1]);

        $this->assertSame(2, $offer->free_units);
    }

    public function test_free_units_is_clamped_at_zero_when_reservations_exceed_the_supply(): void
    {
        $offer = (new Offer)->forceFill(['available_units' => 1, 'reserved_units' => 3]);

        $this->assertSame(0, $offer->free_units);
    }

    public function test_reserved_units_defaults_to_zero_on_an_unsaved_offer(): void
    {
        $offer = new Offer(['available_units' => 2]);

        $this->assertSame(2, $offer->free_units);
    }
}
