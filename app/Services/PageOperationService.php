<?php

namespace App\Services;

use App\Enums\DocumentSourceType;
use App\Enums\DocumentStatus;
use App\Models\Document;
use App\Models\DocumentOverlay;
use App\Models\DocumentVersion;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Orchestrates page-level operations: it reads a document's active bytes, asks the Python
 * service to produce new PDF(s), and persists the results non-destructively (the Golden Rule).
 *
 * - reorder / rotate / delete  → one new {@see DocumentVersion} (via {@see organize()}).
 * - split                      → one new {@see Document} per output range.
 * - merge                      → one new {@see Document} combining several.
 *
 * Originals on disk are never modified; every result is a brand-new file.
 */
class PageOperationService
{
    public function __construct(protected PdfServiceClient $pdf) {}

    /**
     * Apply a reorder/rotate/delete in one pass, storing the result as a new version.
     *
     * @param  list<array{source: int, rotate: int}>  $pages  the desired final pages, in order
     */
    public function organize(Document $document, array $pages, User $user, ?string $label = null): DocumentVersion
    {
        $outputs = $this->pdf->pages(
            [$this->fileFor($document)],
            ['op' => 'organize', 'pages' => $pages],
        );

        return $this->storeVersion($document, $user, $outputs[0], $label ?? __('Reorganized pages'));
    }

    /**
     * Restore an earlier version by appending a copy of it as the new latest version. The
     * history stays append-only, so nothing is overwritten or lost.
     */
    public function restore(Document $document, DocumentVersion $version, User $user): DocumentVersion
    {
        $bytes = (string) Storage::disk($document->disk)->get($version->path);

        return $this->storeVersion(
            $document,
            $user,
            ['page_count' => $version->page_count, 'content_base64' => base64_encode($bytes)],
            __('Restored from v:number', ['number' => $version->version_number]),
        );
    }

    /**
     * Flatten the document's overlay edits onto a copy of its active bytes, storing the
     * result as a new version. The overlay layer is then cleared: the edits are "committed"
     * into the baked version, so re-baking can never compound them. The original is untouched.
     */
    public function bake(Document $document, User $user): DocumentVersion
    {
        $overlays = array_values($document->overlays()
            ->orderBy('z_index')
            ->orderBy('order')
            ->orderBy('id')
            ->get()
            ->map(fn (DocumentOverlay $overlay): array => [
                'type' => $overlay->type->value,
                'page_number' => $overlay->page_number,
                'z_index' => $overlay->z_index,
                'order' => $overlay->order,
                'payload' => $overlay->payload,
            ])
            ->all());

        $output = $this->pdf->bake(
            (string) Storage::disk($document->disk)->get($document->activePath()),
            $overlays,
            $document->original_filename,
        );

        return DB::transaction(function () use ($document, $user, $output): DocumentVersion {
            $version = $this->storeVersion($document, $user, $output, __('Baked edits'));
            $document->overlays()->delete();

            return $version;
        });
    }

    /**
     * Split a document into several brand-new documents (one per output range).
     *
     * @param  array{ranges?: list<array{0: int, 1: int}>, every?: int}  $spec
     * @return Collection<int, Document>
     */
    public function split(Document $document, array $spec, User $user): Collection
    {
        $outputs = $this->pdf->pages(
            [$this->fileFor($document)],
            array_merge(['op' => 'split'], $spec),
        );

        return collect($outputs)->map(fn (array $output, int $index): Document => $this->storeDocument(
            $user,
            $output,
            $document->title.' '.__('(part :number)', ['number' => $index + 1]),
            $this->derivedFilename($document->original_filename, 'part-'.($index + 1)),
            $document->source_type,
            ['split_from' => $document->id],
        ))->values();
    }

    /**
     * Merge several documents (in the given order) into one new document.
     *
     * @param  Collection<int, Document>  $documents
     */
    public function merge(Collection $documents, User $user): Document
    {
        $files = array_values($documents->map(fn (Document $document): array => $this->fileFor($document))->all());

        $outputs = $this->pdf->pages($files, ['op' => 'merge']);

        return $this->storeDocument(
            $user,
            $outputs[0],
            __('Merged document'),
            'merged.pdf',
            $this->combinedSourceType($documents),
            ['merged_from' => $documents->pluck('id')->all()],
        );
    }

    /**
     * Build the multipart file payload (active bytes + original filename) for a document.
     *
     * @return array{contents: string, filename: string}
     */
    protected function fileFor(Document $document): array
    {
        return [
            'contents' => (string) Storage::disk($document->disk)->get($document->activePath()),
            'filename' => $document->original_filename,
        ];
    }

    /**
     * Persist a service output as the next version of the given document.
     *
     * @param  array{page_count: int, content_base64: string}  $output
     */
    protected function storeVersion(Document $document, User $user, array $output, string $label): DocumentVersion
    {
        $binary = base64_decode($output['content_base64']);
        $path = 'documents/versions/'.Str::uuid()->toString().'.pdf';
        Storage::disk($document->disk)->put($path, $binary);

        return $document->versions()->create([
            'version_number' => (int) $document->versions()->max('version_number') + 1,
            'path' => $path,
            'page_count' => $output['page_count'],
            'size_bytes' => strlen($binary),
            'label' => $label,
            'created_by' => $user->id,
        ]);
    }

    /**
     * Persist a service output as a brand-new document owned by the user.
     *
     * @param  array{page_count: int, content_base64: string}  $output
     * @param  array<string, mixed>  $meta
     */
    protected function storeDocument(
        User $user,
        array $output,
        string $title,
        string $filename,
        DocumentSourceType $sourceType,
        array $meta,
    ): Document {
        $binary = base64_decode($output['content_base64']);
        $path = 'documents/'.Str::uuid()->toString().'.pdf';
        Storage::disk('pdfs')->put($path, $binary);

        $document = $user->documents()->create([
            'title' => $title,
            'original_filename' => $filename,
            'disk' => 'pdfs',
            'path' => $path,
            'page_count' => $output['page_count'],
            'size_bytes' => strlen($binary),
            'mime' => 'application/pdf',
            'source_type' => $sourceType,
            'status' => DocumentStatus::Ready,
            'meta' => $meta,
        ]);

        $this->generateCover($document, $binary);

        return $document;
    }

    /**
     * Generate and store a cover thumbnail for a new document. Best-effort: never blocks.
     */
    protected function generateCover(Document $document, string $binary): void
    {
        try {
            $thumbnails = $this->pdf->thumbnails($binary, [1], 96, $document->original_filename);

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
     * The shared source type when every input agrees, otherwise "mixed".
     *
     * @param  Collection<int, Document>  $documents
     */
    protected function combinedSourceType(Collection $documents): DocumentSourceType
    {
        $types = $documents->pluck('source_type')->unique();

        return $types->count() === 1 ? $types->first() : DocumentSourceType::Mixed;
    }

    /**
     * Derive a "<base>-<suffix>.pdf" filename from the original upload name.
     */
    protected function derivedFilename(string $original, string $suffix): string
    {
        $base = Str::of($original)->beforeLast('.')->trim();

        return ($base->isEmpty() ? 'document' : (string) $base).'-'.$suffix.'.pdf';
    }
}
