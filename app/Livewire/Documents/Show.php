<?php

namespace App\Livewire\Documents;

use App\Enums\ExportFormat;
use App\Enums\ExportJobStatus;
use App\Jobs\ExportDocumentJob;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\ExportJob;
use App\Services\PageOperationService;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Document viewer. Rendering happens client-side with PDF.js (see resources/js/pdf-editor);
 * this component authorizes access, exposes the active bytes (latest version or original),
 * and hosts the page-level actions that produce new versions/documents: split and restore.
 * Reorder/rotate/delete live in the dedicated {@see Organize} page.
 */
class Show extends Component
{
    public Document $document;

    public string $splitMode = 'every';

    public int $splitEvery = 1;

    public string $splitRanges = '';

    public function mount(Document $document): void
    {
        $this->authorize('view', $document);

        $this->document = $document;
    }

    /**
     * The URL the viewer should load: the latest version if one exists, else the original.
     */
    #[Computed]
    public function activeUrl(): string
    {
        $version = $this->document->latestVersion;

        return $version
            ? route('documents.versions.file', [$this->document, $version])
            : route('documents.file', $this->document);
    }

    /**
     * This document's versions, newest first (for the versions panel).
     *
     * @return Collection<int, DocumentVersion>
     */
    #[Computed]
    public function versions(): Collection
    {
        return $this->document->versions()->latest('version_number')->get();
    }

    /**
     * The most recent Word-export job for this document (newest first), if any. The export
     * modal polls this for status while a job is in flight.
     */
    #[Computed]
    public function latestExport(): ?ExportJob
    {
        return $this->document->exportJobs()->first();
    }

    /**
     * How many overlay edits are saved but not yet applied (baked) into a version. Everything
     * downstream — the rendered page, downloads, Word export — reads the document's *bytes*,
     * so unapplied edits would otherwise be invisible. The viewer surfaces this count.
     */
    #[Computed]
    public function pendingEditCount(): int
    {
        return $this->document->overlays()->count();
    }

    /**
     * Flatten the saved-but-unapplied edits into a new version, so the document's bytes finally
     * carry them. Returns whether the document is now up to date.
     */
    public function applyEdits(PageOperationService $pageOperations): bool
    {
        $this->authorize('update', $this->document);

        if ($this->pendingEditCount() === 0) {
            return true;
        }

        try {
            $pageOperations->bake($this->document, Auth::user());
        } catch (Throwable $exception) {
            report($exception);
            $this->addError('apply', __('We could not apply your edits. Please try again.'));

            return false;
        }

        unset($this->pendingEditCount, $this->versions, $this->activeUrl);

        return true;
    }

    /**
     * Apply pending edits and reload the viewer so the page shows the edited document.
     */
    public function applyEditsAndRefresh(PageOperationService $pageOperations): void
    {
        if (! $this->applyEdits($pageOperations)) {
            return;
        }

        Flux::toast(variant: 'success', text: __('Edits applied — saved as a new version.'));

        $this->redirectRoute('documents.show', $this->document, navigate: true);
    }

    /**
     * Queue a smart PDF → DOCX export. Any saved-but-unapplied edits are baked in first, so the
     * Word file reflects what the user actually sees and edited — exporting the pre-edit bytes
     * was the single most confusing thing about this flow. No-op while a job is already running,
     * so double-clicks (or an impatient poll) cannot stack jobs. The original is never modified.
     */
    public function exportToWord(PageOperationService $pageOperations): void
    {
        $this->authorize('view', $this->document);

        $alreadyRunning = $this->document->exportJobs()
            ->whereIn('status', [ExportJobStatus::Queued, ExportJobStatus::Processing])
            ->exists();

        if ($alreadyRunning) {
            return;
        }

        if ($this->pendingEditCount() > 0 && ! $this->applyEdits($pageOperations)) {
            return;
        }

        $job = $this->document->exportJobs()->create([
            'created_by' => Auth::id(),
            'format' => ExportFormat::Docx,
            'status' => ExportJobStatus::Queued,
        ]);

        ExportDocumentJob::dispatch($job);

        unset($this->latestExport);

        Flux::toast(text: __('Preparing your Word document… we’ll have it ready shortly.'));
    }

    /**
     * Download the document as the user sees it: pending edits are applied first, then the
     * freshly baked bytes are streamed back.
     */
    public function downloadEdited(PageOperationService $pageOperations): ?StreamedResponse
    {
        $this->authorize('download', $this->document);

        if (! $this->applyEdits($pageOperations)) {
            return null;
        }

        $title = trim($this->document->title);

        return Storage::disk($this->document->disk)->download(
            $this->document->activePath(),
            ($title === '' ? 'document' : $title).'.pdf',
        );
    }

    /**
     * Split the document into several new documents, then return to the library.
     */
    public function split(PageOperationService $pageOperations): void
    {
        $this->authorize('update', $this->document);

        $spec = $this->buildSplitSpec();

        if ($spec === null) {
            return;
        }

        try {
            $documents = $pageOperations->split($this->document, $spec, Auth::user());
        } catch (Throwable $exception) {
            report($exception);
            $this->addError('split', __('We could not split this document. Please try again.'));

            return;
        }

        Flux::modal('split')->close();
        Flux::toast(variant: 'success', text: trans_choice(
            '{1} Created :count new document.|[2,*] Created :count new documents.',
            $documents->count(),
            ['count' => $documents->count()],
        ));

        $this->redirectRoute('documents.index', navigate: true);
    }

    /**
     * Restore an earlier version by appending a copy as the new latest version.
     */
    public function restoreVersion(int $versionId, PageOperationService $pageOperations): void
    {
        $this->authorize('update', $this->document);

        $version = $this->document->versions()->findOrFail($versionId);

        $pageOperations->restore($this->document, $version, Auth::user());

        Flux::modal('versions')->close();
        Flux::toast(variant: 'success', text: __('Version restored as the latest.'));

        $this->redirectRoute('documents.show', $this->document, navigate: true);
    }

    public function render(): View
    {
        return view('livewire.documents.show')->title($this->document->title);
    }

    /**
     * Translate the split form fields into a service spec, or add an error and return null.
     *
     * @return array{every: int}|array{ranges: list<array{0: int, 1: int}>}|null
     */
    protected function buildSplitSpec(): ?array
    {
        $pageCount = $this->document->activePageCount();

        if ($pageCount <= 1) {
            $this->addError('split', __('This document has only one page — there is nothing to split.'));

            return null;
        }

        if ($this->splitMode === 'every') {
            $every = (int) $this->splitEvery;

            if ($every < 1 || $every >= $pageCount) {
                $this->addError('split', __('Choose a chunk size between 1 and :max.', ['max' => $pageCount - 1]));

                return null;
            }

            return ['every' => $every];
        }

        $ranges = $this->parseRanges($this->splitRanges, $pageCount);

        if ($ranges === []) {
            $this->addError('split', __('Enter valid page ranges, e.g. "1-2, 3-5".'));

            return null;
        }

        return ['ranges' => $ranges];
    }

    /**
     * Parse a "1-2, 3-5, 7" string into validated inclusive [start, end] pairs.
     *
     * @return list<array{0: int, 1: int}>
     */
    protected function parseRanges(string $input, int $pageCount): array
    {
        $ranges = [];

        foreach (Str::of($input)->explode(',') as $chunk) {
            $chunk = trim($chunk);

            if ($chunk === '') {
                continue;
            }

            [$start, $end] = str_contains($chunk, '-')
                ? array_pad(explode('-', $chunk, 2), 2, '')
                : [$chunk, $chunk];

            if (! is_numeric(trim($start)) || ! is_numeric(trim($end))) {
                return [];
            }

            $start = (int) trim($start);
            $end = (int) trim($end);

            if ($start < 1 || $end > $pageCount || $start > $end) {
                return [];
            }

            $ranges[] = [$start, $end];
        }

        return $ranges;
    }
}
