<?php

namespace App\Jobs;

use App\Enums\ImportStatus;
use App\Models\Import;
use App\Services\ImportService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Pinned to the `database` connection so ImportService::accept() can write the import and
 * this job in one transaction. Duplicate jobs are kept apart by the import's status (see
 * `ImportService::claim()`), not by `ShouldBeUnique`, whose cache lock sits outside it.
 */
class ProcessImportJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /**
     * Must stay below the connection's `retry_after`, or a slow attempt would be handed to
     * a second worker.
     */
    public int $timeout = 60;

    /**
     * Create a new job instance.
     */
    public function __construct(public Import $import)
    {
        $this->onConnection('database');
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [10, 60];
    }

    /**
     * Execute the job.
     */
    public function handle(ImportService $importService): void
    {
        // No job instance when run directly, outside a queue.
        $importService->process($this->import, $this->job?->uuid() ?? (string) Str::uuid());
    }

    /**
     * Runs once the attempts are exhausted, so an import never hangs in `processing`.
     *
     * Only an unclaimed import, or one this job holds and has not completed, is marked
     * failed: a duplicate that gives up must not fail another job's work.
     *
     * Accepted edge: a duplicate giving up on a `pending` import before the owner starts
     * makes a client see `failed`, then `completed`. Duplicates only come from a manual
     * re-dispatch.
     */
    public function failed(?Throwable $exception): void
    {
        Log::error('Import processing failed', ['import_id' => $this->import->getKey(), 'exception' => $exception]);

        $claimant = $this->job?->uuid();

        Import::query()
            ->whereKey($this->import->getKey())
            ->where(function (Builder $query) use ($claimant): void {
                $query->where('status', ImportStatus::Pending);

                if ($claimant !== null) {
                    $query->orWhere(fn (Builder $query) => $query
                        ->where('claimed_by', $claimant)
                        ->where('status', '!=', ImportStatus::Completed));
                }
            })
            ->update([
                'status' => ImportStatus::Failed,
                'error' => $this->describe($exception),
                'completed_at' => now(),
            ]);
    }

    /**
     * `error` is public: cut the SQL, bindings and connection details a QueryException
     * appends. The full exception is logged by `failed()`.
     */
    private function describe(?Throwable $exception): string
    {
        if ($exception === null) {
            return 'Unknown error';
        }

        $message = Str::before($exception->getMessage(), ' (Connection:');

        return Str::limit($message !== '' ? $message : $exception::class, 500);
    }
}
