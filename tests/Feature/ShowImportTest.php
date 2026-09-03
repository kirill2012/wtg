<?php

namespace Tests\Feature;

use App\Models\Import;
use App\Models\Supplier;
use Closure;
use Database\Factories\ImportFactory;
use Database\Seeders\SupplierSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ShowImportTest extends TestCase
{
    use RefreshDatabase;

    private Supplier $supplier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(SupplierSeeder::class);
        $this->supplier = Supplier::query()->where('slug', 'supplier-a')->sole();
    }

    public function test_it_shows_the_import_with_the_fields_of_the_task(): void
    {
        // Moments are pinned to the second: without the serializer in AppServiceProvider,
        // Carbon's toJSON() would render `2026-09-01T10:00:02.000000Z`.
        $this->travelTo('2026-09-01 10:00:02');
        $import = Import::factory()->for($this->supplier)->create([
            'external_import_id' => 'import-2026-09-01-001',
            'sent_at' => '2026-09-01 10:00:00',
            'total_offers' => 20,
        ]);

        $this->getJson(route('imports.show', $import))
            ->assertOk()
            ->assertExactJson([
                'data' => [
                    'id' => $import->id,
                    'supplier' => 'supplier-a',
                    'external_import_id' => 'import-2026-09-01-001',
                    'sent_at' => '2026-09-01T10:00:00Z',
                    'status' => 'pending',
                    'total_offers' => 20,
                    'processed_offers' => 0,
                    'error' => null,
                    'created_at' => '2026-09-01T10:00:02Z',
                    'completed_at' => null,
                ],
            ]);
    }

    /**
     * @param  Closure(ImportFactory): ImportFactory  $state
     * @param  array<string, mixed>  $expected
     */
    #[DataProvider('statuses')]
    public function test_it_reports_the_import_in_each_status(Closure $state, array $expected): void
    {
        $this->travelTo('2026-09-01 10:00:04');
        $import = $state(Import::factory()->for($this->supplier))->create();

        $this->getJson(route('imports.show', $import))
            ->assertOk()
            ->assertJson(['data' => $expected], strict: true);
    }

    /**
     * @return array<string, array{Closure(ImportFactory): ImportFactory, array<string, mixed>}>
     */
    public static function statuses(): array
    {
        return [
            'pending' => [
                fn (ImportFactory $factory): ImportFactory => $factory->state(['total_offers' => 20]),
                ['status' => 'pending', 'processed_offers' => 0, 'error' => null, 'completed_at' => null],
            ],
            'processing' => [
                fn (ImportFactory $factory): ImportFactory => $factory->processing()->state(['total_offers' => 20, 'processed_offers' => 7]),
                ['status' => 'processing', 'processed_offers' => 7, 'error' => null, 'completed_at' => null],
            ],
            'completed' => [
                fn (ImportFactory $factory): ImportFactory => $factory->completed(20),
                ['status' => 'completed', 'processed_offers' => 20, 'error' => null, 'completed_at' => '2026-09-01T10:00:04Z'],
            ],
            'failed' => [
                fn (ImportFactory $factory): ImportFactory => $factory->failed('Lock wait timeout exceeded')->state(['total_offers' => 20, 'processed_offers' => 12]),
                ['status' => 'failed', 'processed_offers' => 12, 'error' => 'Lock wait timeout exceeded', 'completed_at' => '2026-09-01T10:00:04Z'],
            ],
        ];
    }

    public function test_it_returns_404_without_naming_the_model_for_an_unknown_import(): void
    {
        $this->getJson(route('imports.show', 999))
            ->assertNotFound()
            ->assertExactJson(['message' => 'Not Found.']);
    }

    public function test_it_returns_404_for_a_non_numeric_id_without_querying_the_database(): void
    {
        $queried = false;
        DB::listen(function () use (&$queried): void {
            $queried = true;
        });

        $this->getJson('/api/imports/abc')
            ->assertNotFound()
            ->assertExactJson(['message' => 'Not Found.']);

        $this->assertFalse($queried);
    }
}
