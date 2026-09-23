<?php

namespace Tests\Feature;

use App\Enums\ImportStatus;
use App\Jobs\ProcessImportJob;
use App\Models\Import;
use App\Models\Supplier;
use Database\Seeders\SupplierSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The import through the real `database` queue and worker, not `Bus::fake()` or
 * `dispatchSync()`: what the request stores is what the job reads, and retries follow
 * the job's `$tries` and `backoff()`.
 */
class ImportQueueLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(SupplierSeeder::class);
        $this->travelTo('2026-09-01 10:00:02');
    }

    public function test_an_accepted_import_is_processed_by_the_worker_and_its_offer_found_by_the_search(): void
    {
        $id = $this->postJson(route('imports.store'), $this->payload())
            ->assertAccepted()
            ->assertJsonPath('data.status', 'pending')
            ->json('data.id');

        $this->assertDatabaseCount('jobs', 1);

        $this->work();

        $this->assertDatabaseCount('jobs', 0);
        $this->getJson(route('imports.show', $id))
            ->assertOk()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.total_offers', 1)
            ->assertJsonPath('data.processed_offers', 1)
            ->assertJsonPath('data.error', null)
            ->assertJsonPath('data.completed_at', '2026-09-01T10:00:02Z');

        $this->getJson(route('properties.index', [
            'city' => 'Barcelona',
            'check_in' => '2026-10-10',
            'check_out' => '2026-10-15',
            'guests' => 2,
        ]))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.code', 'BCN-0001')
            ->assertJsonPath('data.0.best_offer.supplier', 'supplier-a')
            ->assertJsonPath('data.0.best_offer.price', 72500)
            ->assertJsonPath('data.0.best_offer.available_units', 2)
            ->assertJsonPath('data.0.best_offer.expires_at', '2026-09-10T23:59:59Z');
    }

    public function test_a_failed_attempt_leaves_the_import_processing_and_the_retry_completes_it(): void
    {
        $import = $this->queuedImportThatFailsOnItsSecondOffer();

        $this->work();

        // Released for a retry, not failed: one attempt of three is spent.
        $this->assertSame(ImportStatus::Processing, $import->refresh()->status);
        $this->assertNull($import->error);
        $this->assertSame(1, DB::table('jobs')->value('attempts'));
        $this->assertDatabaseCount('failed_jobs', 0);

        // The retry waits out the first backoff.
        $this->work();
        $this->assertSame(1, DB::table('jobs')->value('attempts'));

        $import->update(['payload' => [$this->offer(), $this->offer(['external_id' => 'offer-a-10002'])]]);
        $this->travel(10)->seconds();
        $this->work();

        $import->refresh();
        $this->assertSame(ImportStatus::Completed, $import->status);
        $this->assertSame(2, $import->processed_offers);
        $this->assertDatabaseCount('jobs', 0);
        $this->assertDatabaseCount('offers', 2);
    }

    public function test_the_import_is_failed_once_every_attempt_is_spent(): void
    {
        $import = $this->queuedImportThatFailsOnItsSecondOffer();

        $this->work();
        $this->travel(10)->seconds();
        $this->work();
        $this->assertSame(ImportStatus::Processing, $import->refresh()->status);

        $this->travel(60)->seconds();
        $this->work();

        $import->refresh();
        $this->assertSame(ImportStatus::Failed, $import->status);
        $this->assertNotNull($import->error);
        $this->assertSame(1, $import->processed_offers);
        $this->assertDatabaseCount('jobs', 0);
        $this->assertDatabaseCount('failed_jobs', 1);
    }

    /**
     * Run one job from the `database` queue, as `queue:work` does, without sleeping when
     * nothing is due.
     */
    private function work(): void
    {
        $this->artisan('queue:work', [
            'connection' => 'database',
            '--once' => true,
            '--sleep' => 0,
        ])->assertSuccessful();
    }

    /**
     * Stored past the request validation on purpose: the unsigned `price` column rejects -1.
     */
    private function queuedImportThatFailsOnItsSecondOffer(): Import
    {
        $offers = [$this->offer(), $this->offer(['external_id' => 'offer-a-10002', 'price' => -1])];

        $import = Import::factory()
            ->for(Supplier::query()->where('slug', 'supplier-a')->sole())
            ->create(['payload' => $offers, 'total_offers' => count($offers)]);

        ProcessImportJob::dispatch($import);

        return $import;
    }

    /**
     * The request body from the task description.
     *
     * @return array<string, mixed>
     */
    private function payload(): array
    {
        return [
            'supplier' => 'supplier-a',
            'external_import_id' => 'import-2026-09-01-001',
            'sent_at' => '2026-09-01T10:00:00Z',
            'offers' => [$this->offer()],
        ];
    }

    /**
     * One offer from the task description, with optional overrides.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function offer(array $overrides = []): array
    {
        return array_replace([
            'external_id' => 'offer-a-10001',
            'property' => [
                'code' => 'BCN-0001',
                'name' => 'Apartment near Sagrada Familia',
                'City' => 'Barcelona',
            ],
            'check_in' => '2026-10-10',
            'check_out' => '2026-10-15',
            'max_guests' => 4,
            'price' => 72500,
            'currency' => 'EUR',
            'available_units' => 2,
            'expires_at' => '2026-09-10T23:59:59Z',
        ], $overrides);
    }
}
