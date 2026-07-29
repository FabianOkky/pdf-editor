<?php

namespace App\Jobs;

use App\Enums\ExportJobStatus;
use App\Models\ExportJob;
use App\Services\ExportService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Runs a PDF → DOCX export asynchronously. The actual work + status transitions live in
 * {@see ExportService}; this job is the thin queue wrapper, with a safety net to mark the
 * row failed if the job itself dies (timeout, worker crash) before the service can.
 */
class ExportDocumentJob implements ShouldQueue
{
    use Queueable;

    /** Give the heavy OCR/convert pipeline room; do not retry (an export is cheap to re-trigger). */
    public int $timeout = 600;

    public int $tries = 1;

    public function __construct(public ExportJob $exportJob) {}

    public function handle(ExportService $exports): void
    {
        $exports->process($this->exportJob);
    }

    /**
     * Last-resort failure handling: if the job blew up before the service marked an outcome,
     * record it as failed so the UI stops waiting.
     */
    public function failed(?Throwable $exception): void
    {
        $job = $this->exportJob->fresh();

        if ($job !== null && $job->status !== ExportJobStatus::Completed) {
            $job->update([
                'status' => ExportJobStatus::Failed,
                'error' => __('We could not export this document to Word. Please try again.'),
            ]);
        }
    }
}
