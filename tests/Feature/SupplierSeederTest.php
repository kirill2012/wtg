<?php

namespace Tests\Feature;

use App\Models\Supplier;
use Database\Seeders\SupplierSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SupplierSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_default_seeder_creates_both_suppliers(): void
    {
        $this->seed();

        $this->assertSame(
            ['supplier-a', 'supplier-b'],
            Supplier::query()->orderBy('slug')->pluck('slug')->all(),
        );
    }

    public function test_seeding_twice_does_not_duplicate_suppliers(): void
    {
        $this->seed(SupplierSeeder::class);
        $this->seed(SupplierSeeder::class);

        $this->assertDatabaseCount('suppliers', 2);
    }
}
