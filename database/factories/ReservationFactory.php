<?php

namespace Database\Factories;

use App\Models\Offer;
use App\Models\Reservation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Reservation>
 */
class ReservationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'offer_id' => Offer::factory(),
            'client_reference' => 'web-order-'.fake()->unique()->regexify('[a-f0-9]{8}'),
            'customer_name' => fake()->name(),
            'customer_email' => fake()->safeEmail(),
            // Price and currency are a snapshot of the offer at booking time.
            'price' => fn (array $attributes) => Offer::query()->findOrFail($attributes['offer_id'])->price,
            'currency' => fn (array $attributes) => Offer::query()->findOrFail($attributes['offer_id'])->currency,
        ];
    }
}
