<?php

namespace App\Services;

use App\Enums\ImportStatus;
use App\Jobs\ProcessImportJob;
use App\Models\Import;
use App\Models\Supplier;
use Illuminate\Support\Carbon;

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
}
