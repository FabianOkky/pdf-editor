<?php

namespace App\Livewire\Documents;

use App\Enums\DocumentOverlayType;
use App\Enums\SignatureType;
use App\Models\Document;
use App\Models\DocumentOverlay;
use App\Models\Signature;
use App\Services\PageOperationService;
use App\Services\PdfServiceClient;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Throwable;

/**
 * The overlay editor. The editing canvas (text, whiteout, highlight, shapes, freehand,
 * image) runs client-side over the PDF.js render (see resources/js/pdf-editor/editor.js)
 * and holds the working overlay list in PDF user space. This component authorizes access,
 * exposes the active bytes + persisted overlays, autosaves the overlay list, and bakes the
 * edits into a new flattened version via {@see PageOperationService}. The original is never
 * modified (the Golden Rule).
 */
class Editor extends Component
{
    /** Max reusable signatures a user may keep, and the max stored data-URL length (~3.7 MB). */
    private const MAX_SIGNATURES = 20;

    private const MAX_SIGNATURE_CHARS = 5_000_000;

    public Document $document;

    public function mount(Document $document): void
    {
        $this->authorize('update', $document);

        $this->document = $document;
    }

    /**
     * The URL the editor renders as its base: the latest version if one exists, else original.
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
     * The persisted overlays, as plain arrays the JS editor can hydrate from.
     *
     * @return list<array{id: int, type: string, page_number: int, payload: array<string, mixed>, z_index: int, order: int}>
     */
    #[Computed]
    public function overlays(): array
    {
        $overlays = $this->document->overlays()
            ->orderBy('z_index')
            ->orderBy('order')
            ->orderBy('id')
            ->get()
            ->map(fn (DocumentOverlay $overlay): array => [
                'id' => $overlay->id,
                'type' => $overlay->type->value,
                'page_number' => $overlay->page_number,
                'payload' => $overlay->payload,
                'z_index' => $overlay->z_index,
                'order' => $overlay->order,
            ])
            ->all();

        return array_values($overlays);
    }

    /**
     * The current user's reusable signatures, as plain arrays the JS editor can place.
     *
     * @return list<array{id: int, name: string, type: string, data: string}>
     */
    #[Computed]
    public function savedSignatures(): array
    {
        return $this->savedSignaturesList();
    }

    /**
     * Replace the document's overlay layer with the editor's current working set (debounced
     * autosave). The whole list is the source of truth, so we swap it atomically.
     *
     * @param  array<int, array<string, mixed>>  $overlays
     */
    public function syncOverlays(array $overlays): void
    {
        $this->authorize('update', $this->document);

        $clean = $this->sanitizeOverlays($overlays);

        DB::transaction(function () use ($clean): void {
            $this->document->overlays()->delete();

            if ($clean !== []) {
                $this->document->overlays()->createMany($clean);
            }
        });

        unset($this->overlays);
    }

    /**
     * Bake the overlays into a new flattened version, then return to the viewer.
     */
    public function bake(PageOperationService $pageOperations): void
    {
        $this->authorize('update', $this->document);

        if ($this->document->overlays()->doesntExist()) {
            $this->addError('bake', __('Add at least one edit before applying.'));

            return;
        }

        try {
            $pageOperations->bake($this->document, Auth::user());
        } catch (Throwable $exception) {
            report($exception);
            $this->addError('bake', __('We could not apply your edits. Please try again.'));

            return;
        }

        Flux::toast(variant: 'success', text: __('Edits applied — saved as a new version.'));
        $this->redirectRoute('documents.show', $this->document, navigate: true);
    }

    /**
     * Detect the document's interactive AcroForm fields so the editor can seed form-fill
     * inputs over them. Returns the detected fields (geometry in PDF user space) to the client.
     *
     * @return array{is_form: bool, fields: list<array<string, mixed>>}
     */
    public function detectFormFields(PdfServiceClient $pdf): array
    {
        $this->authorize('update', $this->document);

        try {
            return $pdf->formFields(
                (string) Storage::disk($this->document->disk)->get($this->document->activePath()),
                $this->document->original_filename,
            );
        } catch (Throwable $exception) {
            report($exception);
            $this->addError('forms', __('We could not read this document’s form fields.'));

            return ['is_form' => false, 'fields' => []];
        }
    }

    /**
     * Save a reusable signature (a PNG/JPEG data URL) for the current user and return the
     * refreshed saved-signatures list. Owner-scoped; rejects non-images and over-quota saves.
     *
     * @return list<array{id: int, name: string, type: string, data: string}>
     */
    public function saveSignature(string $data, string $type, ?string $name = null): array
    {
        $validator = Validator::make(['data' => $data, 'name' => $name], [
            'data' => ['required', 'string', 'max:'.self::MAX_SIGNATURE_CHARS, 'regex:/^data:image\/(png|jpeg);base64,/'],
            'name' => ['nullable', 'string', 'max:60'],
        ]);

        if ($validator->fails() || Auth::user()->signatures()->count() >= self::MAX_SIGNATURES) {
            $this->addError('signature', __('That signature could not be saved.'));

            return $this->savedSignaturesList();
        }

        Auth::user()->signatures()->create([
            'name' => filled($name) ? trim($name) : __('Signature'),
            'type' => SignatureType::tryFrom($type) ?? SignatureType::Draw,
            'data' => $data,
        ]);

        unset($this->savedSignatures);

        return $this->savedSignaturesList();
    }

    /**
     * Delete one of the current user's saved signatures (owner-scoped) and return the
     * refreshed list. A non-owned or missing id is a harmless no-op.
     *
     * @return list<array{id: int, name: string, type: string, data: string}>
     */
    public function deleteSignature(int $id): array
    {
        Auth::user()->signatures()->whereKey($id)->delete();

        unset($this->savedSignatures);

        return $this->savedSignaturesList();
    }

    public function render(): View
    {
        return view('livewire.documents.editor')->title(__('Edit :title', ['title' => $this->document->title]));
    }

    /**
     * The current user's saved signatures as plain arrays (id, name, type, data URL).
     *
     * @return list<array{id: int, name: string, type: string, data: string}>
     */
    protected function savedSignaturesList(): array
    {
        return array_values(Auth::user()->signatures()
            ->latest()
            ->get()
            ->map(fn (Signature $signature): array => [
                'id' => $signature->id,
                'name' => $signature->name,
                'type' => $signature->type->value,
                'data' => $signature->data,
            ])
            ->all());
    }

    /**
     * Coerce and validate the incoming overlays: known type, page in range, sane numbers.
     * Anything invalid is dropped so a tampered client can't persist garbage.
     *
     * @param  array<int, array<string, mixed>>  $overlays
     * @return list<array{type: string, page_number: int, payload: array<string, mixed>, z_index: int, order: int}>
     */
    protected function sanitizeOverlays(array $overlays): array
    {
        $maxPage = $this->document->activePageCount();
        $clean = [];

        foreach (array_values($overlays) as $index => $overlay) {
            $type = DocumentOverlayType::tryFrom((string) ($overlay['type'] ?? ''));
            $page = (int) ($overlay['page_number'] ?? 0);
            $payload = $overlay['payload'] ?? null;

            if ($type === null || $page < 1 || $page > $maxPage || ! is_array($payload)) {
                continue;
            }

            $clean[] = [
                'type' => $type->value,
                'page_number' => $page,
                'payload' => $payload,
                'z_index' => (int) ($overlay['z_index'] ?? 0),
                'order' => (int) ($overlay['order'] ?? $index),
            ];
        }

        return $clean;
    }
}
