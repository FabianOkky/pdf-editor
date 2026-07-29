<?php

namespace App\Services;

use App\Enums\ExportJobStatus;
use App\Models\Document;
use App\Models\ExportJob;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Runs a single {@see ExportJob}: reads a document's active bytes, asks the Python service for
 * a smart PDF → DOCX conversion (OCR-first for scans), stores the produced file, and records
 * the outcome on the job row. The source document is never modified — the `.docx` is a
 * brand-new artifact (the Golden Rule). All failures are caught and surfaced on the job.
 */
class ExportService
{
    public function __construct(protected PdfServiceClient $pdf) {}

    /**
     * Process the export end-to-end, transitioning the job queued → processing → completed,
     * or → failed (with a user-safe message) on any error. Returns the refreshed job.
     */
    public function process(ExportJob $job): ExportJob
    {
        $job->update(['status' => ExportJobStatus::Processing]);

        try {
            $this->convert($job);
        } catch (Throwable $exception) {
            report($exception);
            $job->update([
                'status' => ExportJobStatus::Failed,
                'error' => __('We could not export this document to Word. Please try again.'),
            ]);
        }

        return $job->refresh();
    }

    /**
     * Convert the document and persist the produced DOCX onto the job. Throws on failure so
     * {@see process()} can mark the job failed.
     */
    protected function convert(ExportJob $job): void
    {
        $document = $job->document;
        $contents = (string) Storage::disk($document->disk)->get($document->activePath());

        $result = $this->pdf->exportDocx($contents, filename: $document->original_filename);

        $binary = base64_decode($result['content_base64']);
        $path = 'exports/'.Str::uuid()->toString().'.docx';
        Storage::disk($document->disk)->put($path, $binary);

        $job->update([
            'status' => ExportJobStatus::Completed,
            'engine' => $result['ocr_applied'] ? 'ocr+python-docx' : 'pdf2docx',
            'result_path' => $path,
            'result_filename' => $this->downloadName($document),
            'result_size_bytes' => strlen($binary),
            'error' => null,
            'meta' => [
                'source_type' => $result['source_type'],
                'ocr_applied' => $result['ocr_applied'],
                'page_count' => $result['page_count'],
            ],
        ]);
    }

    /**
     * A friendly ".docx" download name derived from the document title.
     */
    protected function downloadName(Document $document): string
    {
        $base = Str::of($document->title)->trim();

        return ($base->isEmpty() ? 'document' : (string) $base).'.docx';
    }
}
