<?php

namespace Database\Factories;

use App\Models\Import;
use App\Models\Offer;
use App\Models\Property;
use App\Models\Supplier;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<Offer>
 */
class OfferFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $checkIn = Carbon::today()->addDays(fake()->numberBetween(7, 180));

        return [
            'supplier_id' => Supplier::factory(),
            'property_id' => Property::factory(),
            // The import must belong to the same supplier as the offer; by the time this
            // closure runs `supplier_id` has already been resolved to a key.
            'import_id' => fn (array $attributes) => Import::factory()->state(['supplier_id' => $attributes['supplier_id']]),
            'external_id' => 'offer-'.fake()->unique()->numerify('#####'),
            'sent_at' => now(),
            'check_in' => $checkIn,
            'check_out' => $checkIn->copy()->addDays(fake()->numberBetween(1, 14)),
            'max_guests' => fake()->numberBetween(1, 6),
            'price' => fake()->numberBetween(5_000, 150_000),
            'currency' => 'EUR',
            'available_units' => fake()->numberBetween(1, 5),
            'reserved_units' => 0,
            'expires_at' => now()->addWeek(),
        ];
    }

    public function expired(): static
    {
        return $this->state(['expires_at' => now()->subMinute()]);
    }

    public function soldOut(int $units = 1): static
    {
        return $this->state([
            'available_units' => $units,
            'reserved_units' => $units,
        ]);
    }

    /**
     * Attach the offer to an existing import, taking the supplier and `sent_at` from it
     * so the three stay consistent.
     */
    public function forImport(Import $import): static
    {
        return $this->state([
            'import_id' => $import->getKey(),
            'supplier_id' => $import->supplier_id,
            'sent_at' => $import->sent_at,
        ]);
    }
}
