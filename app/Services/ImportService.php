<?php

namespace App\Services;

use App\Enums\ImportStatus;
use App\Exceptions\ImportIdReusedException;
use App\Jobs\ProcessImportJob;
use App\Models\Import;
use App\Models\Supplier;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class ImportService
{
    /**
     * Record an import and queue its processing in one transaction: the job goes to the
     * `database` queue on the same connection, so both rows commit together.
     *
     * A resent import returns the existing row and queues nothing; a resend with different
     * content is an ImportIdReusedException.
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
     * @param  array{sent_at: string, offers: list<array<string, mixed>>}  $data
     */
    private function sameImportOrConflict(Import $import, array $data): Import
    {
        $hasSameContent = $import->sent_at->equalTo(Carbon::parse($data['sent_at']))
            && $this->withSortedKeys($import->payload) === $this->withSortedKeys($data['offers']);

        if (! $hasSameContent) {
            throw new ImportIdReusedException;
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
}
