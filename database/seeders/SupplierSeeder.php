<?php

namespace Database\Seeders;

use App\Models\Supplier;
use Illuminate\Database\Seeder;

class SupplierSeeder extends Seeder
{
    /**
     * @var array<string, string> slug => display name
     */
    private const array SUPPLIERS = [
        'supplier-a' => 'Supplier A',
        'supplier-b' => 'Supplier B',
    ];

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        foreach (self::SUPPLIERS as $slug => $name) {
            Supplier::query()->firstOrCreate(['slug' => $slug], ['name' => $name]);
        }
    }
}
