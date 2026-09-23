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
use Symfony\Component\HttpFoundation\Response;

class ImportService
{
    /**
     * Record an import and queue its processing in one transaction: the job goes to the
     * `database` queue on the same connection, so both rows commit together.
     *
     * A resent import returns the existing row and queues nothing; a resend with different
     * content is a 409.
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
            return $this->sameImportOrConflict($existing, $data);
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
            // A concurrent request recorded it first; read outside the rolled-back transaction.
            return $this->sameImportOrConflict(Import::query()->where($keys)->firstOrFail(), $data);
        }
    }

    /**
     * Apply the payload offer by offer, each in its own transaction, once the job has
     * claimed the import. A re-run after a failure is safe: `applyOffer()` is idempotent.
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

        foreach ($import->payload as $offerData) {
            // Outside the transaction, so a property another worker has just committed is visible.
            $property = $this->findOrCreateProperty($offerData['property']);

            // Retried on a deadlock between concurrent inserts.
            DB::transaction(fn () => $this->applyOffer($import, $property, $offerData), attempts: 3);

            $import->increment('processed_offers');
        }

        $import->update([
            'status' => ImportStatus::Completed,
            'completed_at' => now(),
        ]);
    }

    /**
     * @param  array{sent_at: string, offers: list<array<string, mixed>>}  $data
     */
    private function sameImportOrConflict(Import $import, array $data): Import
    {
        $hasSameContent = $import->sent_at->equalTo(Carbon::parse($data['sent_at']))
            && $this->withSortedKeys($import->payload) === $this->withSortedKeys($data['offers']);

        if (! $hasSameContent) {
            abort(Response::HTTP_CONFLICT, 'This external_import_id was already used with different content.');
        }

        return $import;
    }

    /**
     * The JSON column does not keep the request's key order, so `===` needs the keys sorted;
     * `==` would take `"1000"` and `"1e3"` for the same id. List order is kept.
     *
     * @param  array<array-key, mixed>  $value
     * @return array<array-key, mixed>
     */
    private function withSortedKeys(array $value): array
    {
        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map(fn (mixed $item): mixed => is_array($item) ? $this->withSortedKeys($item) : $item, $value);
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
