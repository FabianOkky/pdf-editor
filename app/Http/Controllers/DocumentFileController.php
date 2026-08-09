<?php

namespace App\Http\Controllers;

use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\ExportJob;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streams document files straight off the private disk. Authorization is enforced by the
 * `can:` route middleware (see routes/web.php); these actions assume access is already granted.
 * The original bytes are served as-is — never re-encoded (the Golden Rule).
 */
class DocumentFileController extends Controller
{
    /**
     * Stream the original PDF inline (used by the PDF.js viewer).
     */
    public function show(Document $document): StreamedResponse
    {
        $disk = Storage::disk($document->disk);

        abort_unless($disk->exists($document->path), 404);

        return $disk->response($document->path, $document->original_filename, [
            'Content-Type' => 'application/pdf',
        ]);
    }

    /**
     * Download the document as it currently stands — the latest baked version if the user has
     * applied any edits, otherwise the original upload. Downloading the pristine original is a
     * separate, explicit action ({@see downloadOriginal()}), because "Download" on an edited
     * document must hand back the edited file.
     */
    public function download(Document $document): StreamedResponse
    {
        $disk = Storage::disk($document->disk);
        $path = $document->activePath();

        abort_unless($disk->exists($path), 404);

        return $disk->download($path, $this->downloadName($document));
    }

    /**
     * Download the immutable original upload, ignoring every edit made since (the Golden Rule
     * means it is always still there, byte-for-byte).
     */
    public function downloadOriginal(Document $document): StreamedResponse
    {
        $disk = Storage::disk($document->disk);

        abort_unless($disk->exists($document->path), 404);

        return $disk->download($document->path, $document->original_filename);
    }

    /**
     * Stream the cover thumbnail (PNG) generated on upload, if any.
     */
    public function thumbnail(Document $document): StreamedResponse
    {
        $path = $document->meta['thumbnail_path'] ?? null;
        $disk = Storage::disk($document->disk);

        abort_if($path === null || ! $disk->exists($path), 404);

        return $disk->response($path, null, ['Content-Type' => 'image/png']);
    }

    /**
     * Stream a specific version's PDF inline. Route-model scoping guarantees the version
     * belongs to the document; the `can:` middleware enforces ownership of the document.
     */
    public function versionShow(Document $document, DocumentVersion $version): StreamedResponse
    {
        $disk = Storage::disk($document->disk);

        abort_unless($disk->exists($version->path), 404);

        return $disk->response($version->path, $this->versionFilename($document, $version), [
            'Content-Type' => 'application/pdf',
        ]);
    }

    /**
     * Download a specific version's PDF as an attachment.
     */
    public function versionDownload(Document $document, DocumentVersion $version): StreamedResponse
    {
        $disk = Storage::disk($document->disk);

        abort_unless($disk->exists($version->path), 404);

        return $disk->download($version->path, $this->versionFilename($document, $version));
    }

    /**
     * Download a completed export's produced file (e.g. the DOCX) as an attachment. Route-model
     * scoping ties the export to its document; the `can:view,document` middleware enforces
     * ownership. Unfinished or missing exports 404.
     */
    public function exportDownload(Document $document, ExportJob $exportJob): StreamedResponse
    {
        abort_unless($exportJob->isDownloadable(), 404);

        $disk = Storage::disk($document->disk);

        abort_unless($disk->exists((string) $exportJob->result_path), 404);

        return $disk->download(
            (string) $exportJob->result_path,
            $exportJob->result_filename ?? 'export.'.$exportJob->format->extension(),
            ['Content-Type' => $exportJob->format->mime()],
        );
    }

    /**
     * A friendly download name for a version, e.g. "Report (v2).pdf".
     */
    protected function versionFilename(Document $document, DocumentVersion $version): string
    {
        return $document->title.' (v'.$version->version_number.').pdf';
    }

    /**
     * A friendly ".pdf" download name derived from the document title.
     */
    protected function downloadName(Document $document): string
    {
        $title = trim($document->title);

        return ($title === '' ? 'document' : $title).'.pdf';
    }
}
