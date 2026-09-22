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
        ];
    }

    /**
     * Snapshot the offer as ReservationService does, keeping any value passed explicitly.
     */
    public function configure(): static
    {
        return $this->afterMaking(function (Reservation $reservation): void {
            $offer = $reservation->offer;

            foreach (['property_id', 'check_in', 'check_out', 'price', 'currency'] as $attribute) {
                $reservation->{$attribute} ??= $offer->{$attribute};
            }
        });
    }
}
