<?php

namespace Database\Factories;

use App\Enums\ImportStatus;
use App\Models\Import;
use App\Models\Supplier;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Import>
 */
class ImportFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'supplier_id' => Supplier::factory(),
            'external_import_id' => 'import-'.fake()->unique()->numerify('####-##-##-###'),
            'sent_at' => now(),
            'status' => ImportStatus::Pending,
            'payload' => [],
            'total_offers' => 0,
            'processed_offers' => 0,
        ];
    }

    public function processing(): static
    {
        return $this->state(['status' => ImportStatus::Processing]);
    }

    public function completed(int $totalOffers = 0): static
    {
        return $this->state([
            'status' => ImportStatus::Completed,
            'total_offers' => $totalOffers,
            'processed_offers' => $totalOffers,
            'completed_at' => now(),
        ]);
    }

    public function failed(string $error = 'Import failed'): static
    {
        return $this->state([
            'status' => ImportStatus::Failed,
            'error' => $error,
            'completed_at' => now(),
        ]);
    }
}
