<?php

namespace App\Livewire\Documents;

use App\Enums\DocumentSourceType;
use App\Enums\DocumentStatus;
use App\Models\Document;
use App\Services\PageOperationService;
use App\Services\PdfServiceClient;
use Flux\Flux;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Livewire\WithPagination;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * The document library: upload new PDFs, browse the current user's documents, and
 * rename / delete / download them. Everything here is scoped to the authenticated user.
 */
#[Title('Documents')]
class Index extends Component
{
    use WithFileUploads;
    use WithPagination;

    /**
     * The pending upload (a Livewire temporary file). Untyped per Livewire convention.
     *
     * @var TemporaryUploadedFile|null
     */
    public $file = null;

    public ?int $renamingId = null;

    public string $renameTitle = '';

    /**
     * IDs of documents selected for merging, in the order chosen.
     *
     * @var array<int, int>
     */
    public array $selected = [];

    /**
     * Validate, store immutably, analyze via the Python service, and create the document.
     */
    public function save(PdfServiceClient $pdf): void
    {
        $this->authorize('create', Document::class);
        $this->validate();

        $upload = $this->file;
        $originalFilename = $upload->getClientOriginalName();
        $contents = $upload->get();

        if ($contents === false) {
            $this->addError('file', __('We could not read the uploaded file. Please try again.'));

            return;
        }

        try {
            $info = $pdf->info($contents, $originalFilename);
        } catch (ConnectionException $exception) {
            report($exception);
            $this->addError('file', __('The PDF processing service is unavailable right now. Please try again in a moment.'));

            return;
        } catch (RequestException $exception) {
            report($exception);

            $message = data_get($exception->response->json(), 'detail') === 'PDF is encrypted/password-protected.'
                ? __('This PDF is password-protected. Remove the password, then upload it again.')
                : __('We could not read this PDF. Please try a different file.');

            $this->addError('file', $message);

            return;
        } catch (Throwable $exception) {
            report($exception);
            $this->addError('file', __('We could not read this PDF. Please try a different file.'));

            return;
        }

        $maxPages = (int) config('services.pdf.max_pages');
        if ($maxPages > 0 && $info['page_count'] > $maxPages) {
            $this->addError('file', __('This PDF has :count pages, which exceeds the :max-page limit.', [
                'count' => $info['page_count'],
                'max' => $maxPages,
            ]));

            return;
        }

        $document = Auth::user()->documents()->create([
            'title' => $this->titleFromFilename($originalFilename),
            'original_filename' => $originalFilename,
            'disk' => 'pdfs',
            'path' => $upload->store('documents', 'pdfs'),
            'page_count' => $info['page_count'],
            'size_bytes' => $upload->getSize(),
            'mime' => 'application/pdf',
            'source_type' => DocumentSourceType::from($info['source_type']),
            'status' => DocumentStatus::Ready,
            'meta' => ['pages' => $info['pages']],
        ]);

        $this->generateThumbnail($pdf, $document, $contents, $originalFilename);

        $this->reset('file');
        $this->resetPage();
        Flux::modal('upload')->close();
        Flux::toast(variant: 'success', text: __('":title" was uploaded.', ['title' => $document->title]));
    }

    /**
     * Open the rename modal for the given document.
     */
    public function startRename(int $documentId): void
    {
        $document = $this->ownedDocument($documentId);
        $this->authorize('update', $document);

        $this->renamingId = $document->id;
        $this->renameTitle = $document->title;

        Flux::modal('rename')->show();
    }

    /**
     * Persist the new title for the document being renamed.
     */
    public function rename(): void
    {
        $document = $this->ownedDocument((int) $this->renamingId);
        $this->authorize('update', $document);

        $this->renameTitle = trim($this->renameTitle);

        $validated = $this->validate([
            'renameTitle' => ['required', 'string', 'max:255'],
        ]);

        $document->update(['title' => $validated['renameTitle']]);

        $this->reset('renamingId', 'renameTitle');
        Flux::modal('rename')->close();
        Flux::toast(variant: 'success', text: __('Document renamed.'));
    }

    /**
     * Soft-delete the document (the original file is kept — editing is non-destructive).
     */
    public function delete(int $documentId): void
    {
        $document = $this->ownedDocument($documentId);
        $this->authorize('delete', $document);

        $document->delete();

        $this->selected = array_values(array_filter($this->selected, fn ($id): bool => (int) $id !== $documentId));
        unset($this->documents, $this->trashedDocuments);
        $this->resetPage();
        Flux::toast(variant: 'success', text: __('Document deleted.'));
    }

    /**
     * Restore one of the current user's soft-deleted documents from the library trash.
     */
    public function restore(int $documentId): void
    {
        $document = Document::onlyTrashed()
            ->where('user_id', Auth::id())
            ->find($documentId);

        if ($document === null) {
            return;
        }

        $this->authorize('restore', $document);

        $document->restore();

        unset($this->documents, $this->trashedDocuments);
        $this->resetPage();
        Flux::toast(variant: 'success', text: __('Document restored.'));
    }

    /**
     * Download from the library without losing autosaved overlay edits. Pending edits are
     * flattened onto a new version first, matching the viewer's Download action.
     */
    public function downloadEdited(int $documentId, PageOperationService $pageOperations): ?StreamedResponse
    {
        $document = $this->ownedDocument($documentId);
        $this->authorize('download', $document);

        try {
            if ($document->overlays()->exists()) {
                $pageOperations->bake($document, Auth::user());
            }
        } catch (Throwable $exception) {
            report($exception);
            $this->addError('download', __('We could not apply your edits for download. Please try again.'));

            return null;
        }

        return Storage::disk($document->disk)->download(
            $document->activePath(),
            $document->downloadFilename(),
        );
    }

    /**
     * Move a selected document one position earlier in the merge order.
     */
    public function moveUp(int $index): void
    {
        if ($index > 0 && isset($this->selected[$index])) {
            [$this->selected[$index - 1], $this->selected[$index]] = [$this->selected[$index], $this->selected[$index - 1]];
        }
    }

    /**
     * Move a selected document one position later in the merge order.
     */
    public function moveDown(int $index): void
    {
        if (isset($this->selected[$index + 1])) {
            [$this->selected[$index], $this->selected[$index + 1]] = [$this->selected[$index + 1], $this->selected[$index]];
        }
    }

    public function clearSelection(): void
    {
        $this->reset('selected');
    }

    /**
     * Merge the selected documents (in the chosen order) into one new document.
     */
    public function merge(PageOperationService $pageOperations): void
    {
        $this->authorize('create', Document::class);

        $documents = $this->selectedDocuments();

        if ($documents->count() < 2) {
            $this->addError('merge', __('Select at least two of your documents to merge.'));

            return;
        }

        try {
            foreach ($documents as $document) {
                if ($document->overlays()->exists()) {
                    $pageOperations->bake($document, Auth::user());
                }
            }

            $merged = $pageOperations->merge($documents, Auth::user());
        } catch (Throwable $exception) {
            report($exception);
            $this->addError('merge', __('We could not merge these documents. Please try again.'));

            return;
        }

        $this->reset('selected');
        unset($this->documents);
        Flux::modal('merge')->close();
        $this->resetPage();
        Flux::toast(variant: 'success', text: __('":title" was created.', ['title' => $merged->title]));
    }

    /**
     * The current user's documents, most recent first.
     *
     * @return LengthAwarePaginator<int, Document>
     */
    #[Computed]
    public function documents(): LengthAwarePaginator
    {
        return Document::query()
            ->where('user_id', Auth::id())
            ->with('latestVersion')
            ->latest()
            ->paginate(12);
    }

    /**
     * Soft-deleted documents owned by the current user, newest deletion first.
     *
     * @return Collection<int, Document>
     */
    #[Computed]
    public function trashedDocuments(): Collection
    {
        return Document::onlyTrashed()
            ->where('user_id', Auth::id())
            ->latest('deleted_at')
            ->get();
    }

    /**
     * The selected documents resolved to models, in the chosen order and scoped to the user
     * (so a tampered selection can never include another user's document).
     *
     * @return Collection<int, Document>
     */
    #[Computed]
    public function selectedDocuments(): Collection
    {
        $ids = array_values(array_unique(array_map('intval', $this->selected)));

        if ($ids === []) {
            return collect();
        }

        $documents = Document::query()
            ->where('user_id', Auth::id())
            ->whereIn('id', $ids)
            ->get()
            ->keyBy('id');

        return collect($ids)
            ->map(fn (int $id): ?Document => $documents->get($id))
            ->filter()
            ->values();
    }

    public function render(): mixed
    {
        return view('livewire.documents.index');
    }

    /**
     * Validation rules for the upload. The max size is driven by config and mirrors the
     * raised Livewire temporary-upload cap (see AppServiceProvider).
     *
     * @return array<string, list<string>>
     */
    protected function rules(): array
    {
        $maxKilobytes = (int) config('services.pdf.max_upload_mb', 25) * 1024;

        return [
            'file' => ['required', 'file', 'mimes:pdf', 'mimetypes:application/pdf', "max:{$maxKilobytes}"],
        ];
    }

    /**
     * Generate and store a cover thumbnail. Best-effort: failures never block the upload.
     */
    protected function generateThumbnail(PdfServiceClient $pdf, Document $document, string $contents, string $filename): void
    {
        try {
            $thumbnails = $pdf->thumbnails($contents, [1], 96, $filename);

            if ($thumbnails === []) {
                return;
            }

            $path = 'thumbnails/'.Str::uuid()->toString().'.png';
            Storage::disk($document->disk)->put($path, base64_decode($thumbnails[0]['image_base64']));

            $document->update(['meta' => array_merge($document->meta ?? [], ['thumbnail_path' => $path])]);
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    /**
     * Resolve a document by id without scoping; ownership is enforced by the policy so an
     * unauthorized id yields a 403 rather than a 404.
     */
    protected function ownedDocument(int $documentId): Document
    {
        return Document::findOrFail($documentId);
    }

    /**
     * Derive a friendly title from the uploaded filename.
     */
    protected function titleFromFilename(string $filename): string
    {
        $base = Str::of($filename)->beforeLast('.')->trim();

        return $base->isEmpty() ? __('Untitled document') : $base->limit(120, '')->value();
    }
}
