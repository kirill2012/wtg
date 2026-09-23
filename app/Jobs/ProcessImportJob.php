<?php

namespace App\Jobs;

use App\Enums\ImportStatus;
use App\Models\Import;
use App\Services\ImportProcessor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Pinned to the `database` connection, so ImportService::accept() writes the import and
 * this job in one transaction. Duplicate jobs are kept apart by ImportProcessor::claim().
 */
class ProcessImportJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /**
     * Below the queue's `retry_after`, or a slow attempt would reach a second worker.
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
    public function handle(ImportProcessor $importProcessor): void
    {
        // No job instance when run outside a queue.
        $importProcessor->process($this->import, $this->job?->uuid() ?? (string) Str::uuid());
    }

    /**
     * Runs once the attempts are exhausted. Marks the import failed only while it is
     * pending or held by this job, so a duplicate job cannot fail another's work.
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
     * `error` is public: drop the SQL and connection details a QueryException appends.
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
