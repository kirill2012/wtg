<?php

namespace App\Jobs;

use App\Enums\ImportStatus;
use App\Models\Import;
use App\Services\ImportService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Processes the offers stored in an import's payload.
 *
 * Unique per import, not per supplier: two imports of one supplier may run on two workers
 * at once. The lock is held between retries, so `$uniqueFor` must exceed the sum of all
 * backoffs — an expired lock would let a second run of the same import in.
 */
class ProcessImportJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $uniqueFor = 3600;

    /**
     * Create a new job instance.
     */
    public function __construct(public Import $import) {}

    public function uniqueId(): string
    {
        return (string) $this->import->getKey();
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
        $importService->process($this->import);
    }

    /**
     * Runs once the attempts are exhausted — a timed-out or crashed attempt counts as one —
     * so an import never hangs in `processing`. Between attempts the status stays `processing`.
     */
    public function failed(?Throwable $exception): void
    {
        $this->import->update([
            'status' => ImportStatus::Failed,
            'error' => $this->describe($exception),
            'completed_at' => now(),
        ]);
    }

    private function describe(?Throwable $exception): string
    {
        if ($exception === null) {
            return 'Unknown error';
        }

        return $exception->getMessage() !== '' ? $exception->getMessage() : $exception::class;
    }
}
