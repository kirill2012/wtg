<?php

namespace Tests\Feature;

use App\Models\Import;
use App\Models\Offer;
use App\Models\Property;
use App\Models\Reservation;
use App\Models\Supplier;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SchemaConstraintsTest extends TestCase
{
    use RefreshDatabase;

    public function test_supplier_slug_is_unique(): void
    {
        Supplier::factory()->create(['slug' => 'supplier-a']);

        $this->expectException(UniqueConstraintViolationException::class);

        Supplier::factory()->create(['slug' => 'supplier-a']);
    }

    public function test_property_code_is_unique_regardless_of_case(): void
    {
        Property::factory()->create(['code' => 'BCN-0001']);

        $this->expectException(UniqueConstraintViolationException::class);

        Property::factory()->create(['code' => 'bcn-0001']);
    }

    public function test_external_import_id_is_unique_within_a_supplier(): void
    {
        $supplier = Supplier::factory()->create();
        Import::factory()->for($supplier)->create(['external_import_id' => 'import-001']);

        $this->expectException(UniqueConstraintViolationException::class);

        Import::factory()->for($supplier)->create(['external_import_id' => 'import-001']);
    }

    public function test_two_suppliers_may_reuse_the_same_external_import_id(): void
    {
        Import::factory()->create(['external_import_id' => 'import-001']);
        Import::factory()->create(['external_import_id' => 'import-001']);

        $this->assertDatabaseCount('imports', 2);
    }

    public function test_offer_external_id_is_unique_within_a_supplier(): void
    {
        $supplier = Supplier::factory()->create();
        Offer::factory()->for($supplier)->create(['external_id' => 'offer-1']);

        $this->expectException(UniqueConstraintViolationException::class);

        Offer::factory()->for($supplier)->create(['external_id' => 'offer-1']);
    }

    public function test_two_suppliers_may_reuse_the_same_offer_external_id(): void
    {
        Offer::factory()->create(['external_id' => 'offer-1']);
        Offer::factory()->create(['external_id' => 'offer-1']);

        $this->assertDatabaseCount('offers', 2);
    }

    public function test_offers_from_both_suppliers_may_point_at_the_same_property(): void
    {
        $property = Property::factory()->create();
        Offer::factory()->count(2)->for($property)->create();

        $this->assertCount(2, $property->offers()->get());
        $this->assertSame(2, Supplier::query()->count());
    }

    public function test_reservation_client_reference_is_unique(): void
    {
        Reservation::factory()->create(['client_reference' => 'web-order-1']);

        $this->expectException(UniqueConstraintViolationException::class);

        Reservation::factory()->create(['client_reference' => 'web-order-1']);
    }
}
