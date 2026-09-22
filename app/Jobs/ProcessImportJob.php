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
 * Processes the offers stored in an import's payload.
 *
 * Pinned to the `database` connection: ImportService::accept() writes the import and this
 * job in one transaction, which no other driver can join. Not `ShouldBeUnique`: a cache
 * lock would sit outside that transaction; two jobs for one import are kept apart by the
 * import's status instead — see `ImportService::claim()`.
 */
class ProcessImportJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /**
     * Seconds per attempt, whatever the worker was started with. Must stay below the
     * connection's `retry_after`, or a slow attempt would be handed to a second worker.
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
        // No uuid only when the job runs outside a queue, e.g. called directly.
        $importService->process($this->import, $this->job?->uuid() ?? (string) Str::uuid());
    }

    /**
     * Runs once the attempts are exhausted — a timed-out or crashed attempt counts as one —
     * so an import never hangs in `processing`. Between attempts the status stays `processing`.
     *
     * A conditional update: only an import nobody has claimed yet, or one this job holds and
     * has not completed. An import being processed by another job stays as it is — a
     * duplicate that gives up must not report someone else's work as failed, nor make it
     * claimable by a third job while the owner is still running.
     *
     * One edge remains: a duplicate that gives up on a `pending` import before its own job
     * has started marks it `failed`; the owner then claims it back and a client polling in
     * between sees `failed` followed by `completed`. Duplicates only come from a manual
     * re-dispatch, so this is accepted rather than guarded against.
     */
    public function failed(?Throwable $exception): void
    {
        Log::error('Import processing failed', ['import_id' => $this->import->getKey(), 'exception' => $exception]);

        // Laravel sets the job instance before calling `failed()`; null only when called directly.
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
     * The `error` field is public, and a QueryException carries the failed statement with
     * its bindings plus the host, port and name of the database. `Str::before()` cuts that
     * tail; `failed()` has already logged the exception untouched.
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
