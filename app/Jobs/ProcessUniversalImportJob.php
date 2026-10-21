<?php

namespace App\Jobs;

use App\Models\ImportJob;
use App\Services\Import\UniversalImportService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessUniversalImportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 1800; // 30 minutes for large files
    public int $tries = 1;      // Idempotency: do not re-run failed import automatically

    protected string $importJobId;

    public function __construct(string $importJobId)
    {
        $this->importJobId = $importJobId;
    }

    public function handle(UniversalImportService $importService): void
    {
        $job = ImportJob::find($this->importJobId);

        if (!$job) {
            Log::error("ProcessUniversalImportJob: Import job '{$this->importJobId}' not found.");
            return;
        }

        if ($job->status === 'completed' || $job->status === 'cancelled') {
            Log::info("ProcessUniversalImportJob: Job '{$this->importJobId}' is already in status '{$job->status}'. Skipping.");
            return;
        }

        $importService->processJob($job);
    }

    public function failed(\Throwable $exception): void
    {
        Log::error("ProcessUniversalImportJob failed for job {$this->importJobId}: " . $exception->getMessage());

        $job = ImportJob::find($this->importJobId);
        if ($job) {
            $job->update([
                'status' => 'failed',
                'error_summary' => 'Queue worker failed: ' . $exception->getMessage(),
                'completed_at' => now(),
            ]);
        }
    }
}
