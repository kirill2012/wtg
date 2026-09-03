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
use Illuminate\Support\Str;

class ImportService
{
    /**
     * Record an import and queue its processing.
     *
     * The pair (supplier, external_import_id) identifies an import. Resending it returns
     * the existing record as it currently stands and does not queue anything, even when
     * the payload differs: a different body under the same id is a supplier error, not a
     * new import. Two identical requests racing each other are settled by the unique key —
     * `firstOrCreate` falls back to `createOrFirst`, which swallows the constraint violation
     * and re-reads the winner's row with `wasRecentlyCreated === false`.
     *
     * @param  array{supplier: string, external_import_id: string, sent_at: string, offers: list<array<string, mixed>>}  $data
     */
    public function accept(array $data): Import
    {
        $supplier = Supplier::query()->where('slug', $data['supplier'])->firstOrFail();

        $import = Import::query()->firstOrCreate(
            [
                'supplier_id' => $supplier->id,
                'external_import_id' => $data['external_import_id'],
            ],
            [
                // The supplier's offset is honoured but the column holds UTC; a naive cast
                // would store `12:00:00+02:00` as 12:00 UTC.
                'sent_at' => Carbon::parse($data['sent_at'])->utc(),
                'status' => ImportStatus::Pending,
                'payload' => $data['offers'],
                'total_offers' => count($data['offers']),
            ],
        );

        if ($import->wasRecentlyCreated) {
            ProcessImportJob::dispatch($import);
        }

        return $import;
    }

    /**
     * Apply every offer of the import's payload, each in its own transaction.
     *
     * Runs inside ProcessImportJob. The counter and the error are reset first, so
     * `processed_offers` always describes the current attempt. A failure part-way leaves
     * the offers already written and the job marks the import failed; a re-run catches up
     * the rest, because every step of `applyOffer()` is idempotent.
     */
    public function process(Import $import): void
    {
        $import->update([
            'status' => ImportStatus::Processing,
            'processed_offers' => 0,
            'error' => null,
            'completed_at' => null,
        ]);

        foreach ($import->payload as $offerData) {
            // Outside the offer's transaction on purpose. Under REPEATABLE READ a transaction
            // reads the snapshot of its first plain SELECT, so a worker that loses the insert
            // race on a new code would not see the winner's row when firstOrCreate re-reads
            // it and would fail instead. In autocommit every statement gets a fresh snapshot.
            // A property left without offers if the step below fails is harmless: it is
            // find-or-create only.
            $property = $this->findOrCreateProperty($offerData['property']);

            // Concurrent inserts on the unique index can deadlock; a deadlocked attempt is
            // rolled back and replayed, which is safe because the step is idempotent.
            DB::transaction(fn () => $this->applyOffer($import, $property, $offerData), attempts: 3);

            $import->increment('processed_offers');
        }

        $import->update([
            'status' => ImportStatus::Completed,
            'completed_at' => now(),
        ]);
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

        // Everything the supplier owns, plus the provenance of this write. `reserved_units`
        // is absent on purpose: imports never touch it.
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

        // A plain lookup first, not a locking one: a locking read of a key that does not
        // exist yet takes a gap lock on the index range around it, and two workers inserting
        // different new offers of one supplier would then deadlock on each other's gaps.
        if (Offer::query()->where($keys)->doesntExist()) {
            try {
                Offer::query()->create(array_merge($keys, $values));

                return;
            } catch (UniqueConstraintViolationException) {
                // Another worker inserted the row between our lookup and our insert. MySQL
                // rolls back only the failed statement, and the locking read below sees the
                // committed row regardless of this transaction's snapshot — which is why
                // createOrFirst, whose fallback is a plain read, is not used here.
            }
        }

        // The row lock is what makes the staleness check trustworthy: without it two workers
        // holding imports sent at 09:00 and 10:00 would both read the old sent_at, both decide
        // to write, and whichever commits last would win — the stale one, in exactly the case
        // the check exists for. The row exists by now, so the lock covers the record only, not
        // a gap. ReservationService takes the same lock, so the two cannot form a cycle.
        $offer = $this->lockedOffer($keys)->firstOrFail();

        // Order is decided by sent_at, not by which import happened to be processed first.
        // Equal timestamps update; skipping is not an error and still counts as processed.
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
