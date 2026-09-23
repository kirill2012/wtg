<?php

namespace Tests\Feature;

use App\Jobs\ProcessImportJob;
use App\Models\Import;
use App\Models\Supplier;
use Database\Seeders\SupplierSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class StoreImportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(SupplierSeeder::class);
    }

    public function test_it_records_the_import_and_queues_its_processing(): void
    {
        Bus::fake();

        $response = $this->postJson(route('imports.store'), $this->payload());

        $import = Import::query()->sole();
        $response
            ->assertAccepted()
            ->assertHeader('Location', route('imports.show', $import))
            ->assertExactJson(['data' => ['id' => $import->id, 'status' => 'pending']]);

        $this->assertTrue($import->supplier->is(Supplier::query()->where('slug', 'supplier-a')->sole()));
        $this->assertSame('import-2026-09-01-001', $import->external_import_id);
        $this->assertSame('2026-09-01 10:00:00', $import->sent_at->toDateTimeString());
        $this->assertSame(1, $import->total_offers);
        $this->assertSame(0, $import->processed_offers);
        $this->assertSame('offer-a-10001', $import->payload[0]['external_id']);
        $this->assertSame('Barcelona', $import->payload[0]['property']['City']);
        $this->assertSame(72500, $import->payload[0]['price']);

        Bus::assertDispatchedTimes(ProcessImportJob::class, 1);
        Bus::assertDispatched(ProcessImportJob::class, fn (ProcessImportJob $job) => $job->import->is($import));
    }

    public function test_resending_the_same_import_neither_duplicates_nor_requeues_it(): void
    {
        Bus::fake();

        $first = $this->postJson(route('imports.store'), $this->payload());

        $second = $this->postJson(route('imports.store'), $this->payload());

        $second->assertAccepted()->assertJsonPath('data.id', $first->json('data.id'));
        $this->assertDatabaseCount('imports', 1);
        Bus::assertDispatchedTimes(ProcessImportJob::class, 1);
    }

    public function test_resending_reports_the_current_state_of_the_existing_import(): void
    {
        Bus::fake();
        $existing = $this->recordedImport();

        $response = $this->postJson(route('imports.store'), $this->payload());

        $response
            ->assertAccepted()
            ->assertExactJson(['data' => ['id' => $existing->id, 'status' => 'completed']]);
        $this->assertDatabaseCount('imports', 1);
        Bus::assertNotDispatched(ProcessImportJob::class);
    }

    public function test_the_same_content_with_reordered_keys_and_another_offset_is_a_resend(): void
    {
        Bus::fake();
        $this->postJson(route('imports.store'), $this->payload())->assertAccepted();

        $resent = $this->payload(['sent_at' => '2026-09-01T12:00:00+02:00']);
        $resent['offers'][0] = array_reverse($resent['offers'][0], preserve_keys: true);

        $this->postJson(route('imports.store'), $resent)->assertAccepted();

        $this->assertDatabaseCount('imports', 1);
        Bus::assertDispatchedTimes(ProcessImportJob::class, 1);
    }

    /**
     * @param  callable(array<string, mixed>): array<string, mixed>  $change
     */
    #[DataProvider('changedContent')]
    public function test_different_content_under_the_same_import_id_is_a_conflict(callable $change): void
    {
        Bus::fake();
        $this->postJson(route('imports.store'), $this->payload())->assertAccepted();

        $this->postJson(route('imports.store'), $change($this->payload()))
            ->assertConflict()
            ->assertExactJson(['message' => 'This external_import_id was already used with different content.']);

        $import = Import::query()->sole();
        $this->assertSame(1, $import->total_offers);
        $this->assertSame(72500, $import->payload[0]['price']);
        Bus::assertDispatchedTimes(ProcessImportJob::class, 1);
    }

    /**
     * @return array<string, array{callable(array<string, mixed>): array<string, mixed>}>
     */
    public static function changedContent(): array
    {
        return [
            'a changed offer' => [fn (array $payload): array => array_replace_recursive($payload, ['offers' => [['price' => 1]]])],
            'an added offer' => [function (array $payload): array {
                $payload['offers'][] = array_replace($payload['offers'][0], ['external_id' => 'offer-a-10002']);

                return $payload;
            }],
            'another sent_at' => [fn (array $payload): array => array_replace($payload, ['sent_at' => '2026-09-01T11:00:00Z'])],
        ];
    }

    public function test_ids_that_are_only_numerically_equal_are_different_content(): void
    {
        Bus::fake();
        $this->postJson(route('imports.store'), array_replace_recursive($this->payload(), ['offers' => [['external_id' => '1000']]]))
            ->assertAccepted();

        $this->postJson(route('imports.store'), array_replace_recursive($this->payload(), ['offers' => [['external_id' => '1e3']]]))
            ->assertConflict();

        Bus::assertDispatchedTimes(ProcessImportJob::class, 1);
    }

    public function test_losing_the_insert_race_returns_the_winner_without_a_second_dispatch(): void
    {
        Bus::fake();

        // Plays the other worker: the row appears after the service's lookup and before
        // its insert, so the insert hits the unique key and the service re-reads.
        $raced = false;
        DB::listen(function (QueryExecuted $query) use (&$raced): void {
            if ($raced || ! str_starts_with($query->sql, 'select') || ! str_contains($query->sql, '`imports`')) {
                return;
            }

            $raced = true;
            $this->recordedImport();
        });

        $response = $this->postJson(route('imports.store'), $this->payload());

        $this->assertTrue($raced);
        $response->assertAccepted()->assertJsonPath('data.status', 'completed');
        $this->assertDatabaseCount('imports', 1);
        Bus::assertNotDispatched(ProcessImportJob::class);
    }

    public function test_the_import_and_its_job_are_written_together(): void
    {
        $this->postJson(route('imports.store'), $this->payload())->assertAccepted();

        $import = Import::query()->sole();
        $job = DB::table('jobs')->sole();
        $command = unserialize(json_decode($job->payload, true)['data']['command']);

        $this->assertInstanceOf(ProcessImportJob::class, $command);
        $this->assertTrue($command->import->is($import));
    }

    public function test_the_job_goes_to_the_database_queue_whatever_the_default_connection(): void
    {
        config(['queue.default' => 'sync']);

        $this->postJson(route('imports.store'), $this->payload())->assertAccepted();

        $this->assertSame('pending', Import::query()->sole()->status->value);
        $this->assertDatabaseCount('jobs', 1);
    }

    public function test_an_import_whose_job_cannot_be_queued_is_not_recorded_either(): void
    {
        DB::beforeExecuting(function (string $query): void {
            if (str_starts_with($query, 'insert into `jobs`')) {
                throw new RuntimeException('The queue is unavailable');
            }
        });

        $this->postJson(route('imports.store'), $this->payload())->assertInternalServerError();

        $this->assertDatabaseCount('imports', 0);
    }

    public function test_the_same_external_import_id_is_a_separate_import_for_another_supplier(): void
    {
        Bus::fake();

        $this->postJson(route('imports.store'), $this->payload())->assertAccepted();
        $this->postJson(route('imports.store'), $this->payload(['supplier' => 'supplier-b']))->assertAccepted();

        $this->assertDatabaseCount('imports', 2);
        $this->assertSame(2, Import::query()->where('external_import_id', 'import-2026-09-01-001')->count());
        Bus::assertDispatchedTimes(ProcessImportJob::class, 2);
    }

    public function test_sent_at_is_stored_as_the_utc_moment(): void
    {
        Bus::fake();

        $this->postJson(route('imports.store'), $this->payload(['sent_at' => '2026-09-01T12:00:00+02:00']))
            ->assertAccepted();

        $this->assertDatabaseHas('imports', ['sent_at' => '2026-09-01 10:00:00']);
    }

    public function test_it_rejects_an_unknown_supplier(): void
    {
        Bus::fake();

        $this->postJson(route('imports.store'), $this->payload(['supplier' => 'supplier-c']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['supplier']);

        $this->assertDatabaseCount('imports', 0);
        Bus::assertNothingDispatched();
    }

    public function test_it_rejects_a_request_missing_the_top_level_fields(): void
    {
        $this->postJson(route('imports.store'), ['supplier' => 'supplier-a', 'offers' => 'not-a-list'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['external_import_id', 'sent_at', 'offers']);
    }

    #[DataProvider('invalidMoments')]
    public function test_it_rejects_a_sent_at_that_is_not_an_exact_iso_moment(string $sentAt): void
    {
        Bus::fake();

        $this->postJson(route('imports.store'), $this->payload(['sent_at' => $sentAt]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['sent_at']);

        Bus::assertNothingDispatched();
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidMoments(): array
    {
        return [
            'a relative word' => ['now'],
            'a relative phrase' => ['next monday'],
            'fractional seconds' => ['2026-09-01T10:00:00.500Z'],
            'no offset' => ['2026-09-01T10:00:00'],
            'a space instead of T' => ['2026-09-01 10:00:00Z'],
            'a date only' => ['2026-09-01'],
        ];
    }

    public function test_it_rejects_a_request_without_a_supplier(): void
    {
        Bus::fake();

        $payload = $this->payload();
        unset($payload['supplier']);

        $this->postJson(route('imports.store'), $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['supplier']);

        Bus::assertNothingDispatched();
    }

    public function test_it_rejects_an_offer_that_is_not_an_object(): void
    {
        $this->postJson(route('imports.store'), $this->payload(['offers' => ['offer-a-10001']]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['offers.0']);
    }

    public function test_it_rejects_an_import_without_offers(): void
    {
        $this->postJson(route('imports.store'), $this->payload(['offers' => []]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['offers']);
    }

    public function test_it_rejects_offers_sent_as_an_object_instead_of_a_list(): void
    {
        $keyed = $this->payload();
        $keyed['offers'] = ['first' => $keyed['offers'][0]];

        $this->postJson(route('imports.store'), $keyed)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['offers']);
    }

    public function test_it_rejects_duplicate_offer_ids_within_one_payload_regardless_of_case(): void
    {
        $payload = $this->payload();
        $payload['offers'][] = array_replace($payload['offers'][0], ['external_id' => 'OFFER-A-10001']);

        $this->postJson(route('imports.store'), $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['offers.0.external_id', 'offers.1.external_id']);
    }

    #[DataProvider('requiredOfferFields')]
    public function test_it_rejects_an_offer_missing_a_required_field(string $field): void
    {
        $payload = $this->payload();
        Arr::forget($payload, "offers.0.{$field}");

        $this->postJson(route('imports.store'), $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(["offers.0.{$field}"]);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function requiredOfferFields(): array
    {
        $fields = [
            'external_id',
            'property.code',
            'property.name',
            'property.City',
            'check_in',
            'check_out',
            'max_guests',
            'price',
            'currency',
            'available_units',
            'expires_at',
        ];

        return array_combine($fields, array_map(fn (string $field): array => [$field], $fields));
    }

    #[DataProvider('invalidOfferFieldValues')]
    public function test_it_rejects_an_offer_with_an_invalid_field_value(string $field, mixed $value): void
    {
        $payload = $this->payload();
        Arr::set($payload, "offers.0.{$field}", $value);

        $this->postJson(route('imports.store'), $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(["offers.0.{$field}"]);
    }

    /**
     * @return array<string, array{string, mixed}>
     */
    public static function invalidOfferFieldValues(): array
    {
        return [
            'external_id longer than the column' => ['external_id', str_repeat('x', 256)],
            'check_in with a time part' => ['check_in', '2026-10-10T00:00:00Z'],
            'check_out with a time part' => ['check_out', '2026-10-15 00:00:00'],
            'max_guests of zero' => ['max_guests', 0],
            'max_guests beyond the cap' => ['max_guests', 65536],
            'negative price' => ['price', -1],
            'zero price' => ['price', 0],
            'price as a string' => ['price', '72500'],
            'max_guests as a string' => ['max_guests', '4'],
            'available_units as a string' => ['available_units', '2'],
            'property as a string' => ['property', 'BCN-0001'],
            'fractional price' => ['price', 725.5],
            'two-letter currency' => ['currency', 'EU'],
            'another currency' => ['currency', 'GBP'],
            'lower-case currency' => ['currency', 'eur'],
            'negative available_units' => ['available_units', -1],
            'expires_at that is not a date' => ['expires_at', 'soon'],
            'expires_at as a relative word' => ['expires_at', 'tomorrow'],
            'expires_at with fractional seconds' => ['expires_at', '2026-09-10T23:59:59.999Z'],
            'expires_at without an offset' => ['expires_at', '2026-09-10T23:59:59'],
        ];
    }

    public function test_it_rejects_an_offer_whose_check_out_is_not_after_its_check_in(): void
    {
        $payload = $this->payload();
        $payload['offers'][0]['check_out'] = $payload['offers'][0]['check_in'];

        $this->postJson(route('imports.store'), $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['offers.0.check_out']);
    }

    /**
     * A completed import recorded from the same request as `payload()`.
     */
    private function recordedImport(): Import
    {
        return Import::factory()
            ->for(Supplier::query()->where('slug', 'supplier-a')->sole())
            ->completed(1)
            ->create([
                'external_import_id' => 'import-2026-09-01-001',
                'sent_at' => '2026-09-01 10:00:00',
                'payload' => $this->payload()['offers'],
            ]);
    }

    /**
     * The request body from the task description, with optional top-level overrides.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_replace([
            'supplier' => 'supplier-a',
            'external_import_id' => 'import-2026-09-01-001',
            'sent_at' => '2026-09-01T10:00:00Z',
            'offers' => [
                [
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
                ],
            ],
        ], $overrides);
    }
}
