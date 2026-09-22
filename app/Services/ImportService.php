<?php

namespace App\Services;

use App\Enums\ImportStatus;
use App\Jobs\ProcessImportJob;
use App\Models\Import;
use App\Models\Offer;
use App\Models\Property;
use App\Models\Supplier;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ImportService
{
    /**
     * Record an import and queue its processing, atomically.
     *
     * The queue is the `database` driver on the same connection, so the import and its job
     * row commit together or not at all. `after_commit` must stay off.
     *
     * A resent (supplier, external_import_id) returns the existing import and queues
     * nothing, even if the payload differs. A race is settled by the unique key; the
     * winner's row is re-read outside the transaction, whose snapshot would not show it.
     *
     * @param  array{supplier: string, external_import_id: string, sent_at: string, offers: list<array<string, mixed>>}  $data
     */
    public function accept(array $data): Import
    {
        $supplier = Supplier::query()->where('slug', $data['supplier'])->firstOrFail();

        $keys = [
            'supplier_id' => $supplier->id,
            'external_import_id' => $data['external_import_id'],
        ];

        $existing = Import::query()->where($keys)->first();

        if ($existing !== null) {
            return $existing;
        }

        try {
            return DB::transaction(function () use ($keys, $data): Import {
                $import = Import::query()->create([
                    ...$keys,
                    // A naive cast would store `12:00:00+02:00` as 12:00 UTC.
                    'sent_at' => Carbon::parse($data['sent_at'])->utc(),
                    'status' => ImportStatus::Pending,
                    'payload' => $data['offers'],
                    'total_offers' => count($data['offers']),
                ]);

                ProcessImportJob::dispatch($import);

                return $import;
            });
        } catch (UniqueConstraintViolationException) {
            return Import::query()->where($keys)->firstOrFail();
        }
    }

    /**
     * Apply every offer of the payload, each in its own transaction, once the job has
     * claimed the import. A failure part-way keeps the offers already written; a re-run
     * catches up the rest, because `applyOffer()` is idempotent.
     *
     * @param  string  $claimant  the uuid of the queued job, the same on every attempt
     */
    public function process(Import $import, string $claimant): void
    {
        if (! $this->claim($import, $claimant)) {
            Log::info('Import claimed by another job or already completed, skipping', [
                'import_id' => $import->getKey(),
                'status' => $import->status->value,
            ]);

            return;
        }

        foreach ($import->payload as $offerData) {
            // Outside the offer's transaction: under REPEATABLE READ, a worker losing the
            // insert race on a new code would not see the winner's row when firstOrCreate
            // re-reads it. A property left without offers is harmless.
            $property = $this->findOrCreateProperty($offerData['property']);

            // Concurrent inserts on the unique index can deadlock; replaying is safe.
            DB::transaction(fn () => $this->applyOffer($import, $property, $offerData), attempts: 3);

            $import->increment('processed_offers');
        }

        $import->update([
            'status' => ImportStatus::Completed,
            'completed_at' => now(),
        ]);
    }

    /**
     * Move the import to `processing` in one conditional UPDATE, so only one job runs it.
     * Claimable: `pending`, `failed` (recovery), and `processing` held by the same uuid
     * (retries and `queue:retry`).
     *
     * Success is read back, not taken from the affected-row count: MySQL counts changed
     * rows, and a retry rewriting identical values changes none.
     */
    private function claim(Import $import, string $claimant): bool
    {
        Import::query()
            ->whereKey($import->getKey())
            ->where(function (Builder $query) use ($claimant): void {
                $query->whereIn('status', [ImportStatus::Pending, ImportStatus::Failed])
                    ->orWhere(fn (Builder $query) => $query
                        ->where('status', ImportStatus::Processing)
                        ->where('claimed_by', $claimant));
            })
            ->update([
                'status' => ImportStatus::Processing,
                'claimed_by' => $claimant,
                'processed_offers' => 0,
                'error' => null,
                'completed_at' => null,
            ]);

        $import->refresh();

        return $import->status === ImportStatus::Processing && $import->claimed_by === $claimant;
    }

    /**
     * Find-or-create only: two suppliers may describe one property differently, and
     * last-writer-wins would make its name flicker from import to import.
     *
     * @param  array{code: string, name: string, City: string}  $data
     */
    private function findOrCreateProperty(array $data): Property
    {
        return Property::query()->firstOrCreate(
            ['code' => $data['code']],
            ['name' => $data['name'], 'city' => $data['City']],
        );
    }

    /**
     * @param  array<string, mixed>  $data  one offer as validated by StoreImportRequest
     */
    private function applyOffer(Import $import, Property $property, array $data): void
    {
        $keys = [
            'supplier_id' => $import->supplier_id,
            'external_id' => $data['external_id'],
        ];

        // `reserved_units` is absent on purpose: imports never touch it.
        $values = [
            'property_id' => $property->id,
            'import_id' => $import->id,
            'sent_at' => $import->sent_at,
            'check_in' => $data['check_in'],
            'check_out' => $data['check_out'],
            'max_guests' => $data['max_guests'],
            'price' => $data['price'],
            'currency' => Str::upper($data['currency']),
            'available_units' => $data['available_units'],
            'expires_at' => Carbon::parse($data['expires_at'])->utc(),
        ];

        // A plain lookup, not a locking one: locking a missing key gap-locks its range,
        // and two workers inserting different new offers would deadlock.
        if (Offer::query()->where($keys)->doesntExist()) {
            try {
                Offer::query()->create(array_merge($keys, $values));

                return;
            } catch (UniqueConstraintViolationException) {
                // Another worker inserted it meanwhile. Only the locking read below sees
                // that row past our snapshot; createOrFirst falls back to a plain read.
            }
        }

        // The row lock makes the staleness check reliable: without it two workers could
        // both read the old sent_at and the staler import could commit last.
        $offer = $this->lockedOffer($keys)->firstOrFail();

        // The newer sent_at wins, whatever the processing order. A skip counts as processed.
        if ($offer->sent_at->greaterThan($import->sent_at)) {
            return;
        }

        $offer->update($values);
    }

    /**
     * @param  array{supplier_id: int, external_id: string}  $keys
     * @return Builder<Offer>
     */
    private function lockedOffer(array $keys): Builder
    {
        return Offer::query()->where($keys)->lockForUpdate();
    }
}
