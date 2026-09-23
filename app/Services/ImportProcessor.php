<?php

namespace App\Services;

use App\Enums\ImportStatus;
use App\Models\Import;
use App\Models\Offer;
use App\Models\Property;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The worker side of an import: applies the payload ImportService::accept() stored.
 */
class ImportProcessor
{
    /**
     * Offers applied per transaction: one commit per offer made a 1000-offer re-import take
     * most of the job's timeout, while a larger batch holds the offers' row locks, which
     * bookings wait on, for longer.
     */
    public const int OFFERS_PER_TRANSACTION = 100;

    /**
     * Apply the payload in batches, each in its own transaction, once the job has claimed the
     * import. A re-run after a failure is safe: `applyOffer()` is idempotent.
     *
     * @param  string  $claimant  the queued job's uuid, the same on every attempt
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

        /** @var array<string, Property> $properties by the code as sent */
        $properties = [];

        foreach (array_chunk($this->inLockOrder($import->payload), self::OFFERS_PER_TRANSACTION) as $batch) {
            // Outside the transaction, so a property another worker has just committed is visible.
            foreach ($batch as $offerData) {
                $properties[$offerData['property']['code']] ??= $this->findOrCreateProperty($offerData['property']);
            }

            // Retried on a deadlock between concurrent writers.
            DB::transaction(function () use ($import, $batch, $properties): void {
                foreach ($batch as $offerData) {
                    $this->applyOffer($import, $properties[$offerData['property']['code']], $offerData);
                }

                Import::query()->whereKey($import->getKey())->increment('processed_offers', count($batch));
            }, attempts: 3);
        }

        $import->update([
            'status' => ImportStatus::Completed,
            'completed_at' => now(),
        ]);
    }

    /**
     * Move the import to `processing` with one conditional UPDATE, so only one job runs it:
     * from `pending`, `failed`, or `processing` held by the same job (a retry).
     *
     * The result is read back: MySQL reports changed rows, and a retry changes none.
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
     * Sorted by external_id, so two jobs updating the same offers lock them in the same order.
     * That makes deadlocks rare, not impossible: inserts take gap locks, and a recount can
     * meet a booking of another offer in the batch. The batch retries on one.
     *
     * @param  list<array<string, mixed>>  $offers
     * @return list<array<string, mixed>>
     */
    private function inLockOrder(array $offers): array
    {
        usort($offers, fn (array $a, array $b): int => strcmp($a['external_id'], $b['external_id']));

        return $offers;
    }

    /**
     * Never updated: two suppliers may describe one property differently.
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

        // No `reserved_units`: reservations own it, bar the recount below.
        $values = [
            'property_id' => $property->id,
            'import_id' => $import->id,
            'sent_at' => $import->sent_at,
            'check_in' => $data['check_in'],
            'check_out' => $data['check_out'],
            'max_guests' => $data['max_guests'],
            'price' => $data['price'],
            'currency' => $data['currency'],
            'available_units' => $data['available_units'],
            'expires_at' => Carbon::parse($data['expires_at'])->utc(),
        ];

        // A plain lookup: a locking read of a missing key would gap-lock and deadlock inserts.
        if (Offer::query()->where($keys)->doesntExist()) {
            try {
                Offer::query()->create(array_merge($keys, $values));

                return;
            } catch (UniqueConstraintViolationException) {
                // Inserted by another worker meanwhile; the locking read below sees it.
            }
        }

        // Locked, so a staler import cannot commit over a newer one.
        $offer = Offer::query()->where($keys)->lockForUpdate()->firstOrFail();

        // A skipped offer still counts as processed.
        if ($this->isWrittenByNewerImport($offer, $import)) {
            return;
        }

        $offer->fill($values);

        // Units booked for another stay do not hold this one. A locking read: reservations
        // committed after this transaction's snapshot must be counted.
        if ($offer->isDirty(['property_id', 'check_in', 'check_out'])) {
            $offer->reserved_units = $offer->reservations()
                ->where('property_id', $offer->property_id)
                ->where('check_in', $data['check_in'])
                ->where('check_out', $data['check_out'])
                ->sharedLock()
                ->count();
        }

        $offer->save();
    }

    /**
     * The newer sent_at wins. On a tie the import recorded later wins, so the outcome does not
     * depend on which job runs last; the same import re-running is not newer than itself.
     */
    private function isWrittenByNewerImport(Offer $offer, Import $import): bool
    {
        if (! $offer->sent_at->equalTo($import->sent_at)) {
            return $offer->sent_at->greaterThan($import->sent_at);
        }

        return $offer->import_id > $import->id;
    }
}
