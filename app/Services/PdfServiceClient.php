<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Thin HTTP client for the Python PDF microservice. Every call carries the shared-secret
 * header; the service binds to localhost only. New capabilities (info, bake, ocr, export…)
 * are added here in their phases. See ARCHITECTURE.md §4 for the contract.
 */
class PdfServiceClient
{
    public function __construct(
        protected string $baseUrl,
        protected ?string $secret,
        protected int $timeout = 30,
        protected int $longTimeout = 300,
    ) {}

    /**
     * Liveness probe used to verify the service is up and the secret matches.
     *
     * @return array{status: string, service: string, version: string}
     */
    public function health(): array
    {
        /** @var array{status: string, service: string, version: string} $body */
        $body = $this->request()->get('/health')->throw()->json();

        return $body;
    }

    /**
     * Whether the service is reachable and healthy. Never throws.
     */
    public function isHealthy(): bool
    {
        try {
            return $this->health()['status'] === 'ok';
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Inspect a PDF: page count, per-page sizes (PDF points), and a source-type guess.
     *
     * @param  string  $contents  the raw PDF bytes
     * @return array{page_count: int, pages: list<array{width: float, height: float}>, source_type: string}
     */
    public function info(string $contents, string $filename = 'document.pdf'): array
    {
        /** @var array{page_count: int, pages: list<array{width: float, height: float}>, source_type: string} $body */
        $body = $this->request()
            ->attach('file', $contents, $filename)
            ->post('/pdf/info')
            ->throw()
            ->json();

        return $body;
    }

    /**
     * Render PDF pages to base64-encoded PNG thumbnails. Pass an empty $pages list for all pages.
     *
     * @param  string  $contents  the raw PDF bytes
     * @param  list<int>  $pages  1-based page numbers to render
     * @return list<array{page: int, width: int, height: int, format: string, image_base64: string}>
     */
    public function thumbnails(string $contents, array $pages = [], int $dpi = 96, string $filename = 'document.pdf'): array
    {
        $form = ['dpi' => $dpi];

        if ($pages !== []) {
            $form['pages'] = implode(',', $pages);
        }

        /** @var array{thumbnails: list<array{page: int, width: int, height: int, format: string, image_base64: string}>} $body */
        $body = $this->request()
            ->attach('file', $contents, $filename)
            ->post('/pdf/thumbnails', $form)
            ->throw()
            ->json();

        return $body['thumbnails'];
    }

    /**
     * Run a page-level operation (organize / split / merge) on one or more PDFs.
     *
     * Pass a single file for `organize`/`split`, or the ordered set of files for `merge`.
     * The service copies pages losslessly (never re-renders) and returns the new PDF(s)
     * base64-encoded: one output for organize/merge, many for split.
     *
     * @param  list<array{contents: string, filename?: string}>  $files
     * @param  array<string, mixed>  $spec  the operation spec (must include an "op" key)
     * @return list<array{page_count: int, content_base64: string}>
     */
    public function pages(array $files, array $spec): array
    {
        $request = $this->request();

        foreach ($files as $index => $file) {
            $request = $request->attach('files', $file['contents'], $file['filename'] ?? "input-{$index}.pdf");
        }

        /** @var array{outputs: list<array{page_count: int, content_base64: string}>} $body */
        $body = $request
            ->post('/pdf/pages', ['spec' => json_encode($spec)])
            ->throw()
            ->json();

        return $body['outputs'];
    }

    /**
     * Flatten overlay edits onto a copy of a PDF, returning the new (baked) document.
     *
     * Each overlay is `{type, page_number, z_index, order, payload}` with geometry in PDF
     * user space (points, bottom-left origin). The original bytes are never modified — the
     * service draws on an in-memory copy (the Golden Rule).
     *
     * @param  string  $contents  the raw PDF bytes to bake onto
     * @param  list<array{type: string, page_number: int, z_index: int, order: int, payload: array<string, mixed>}>  $overlays
     * @return array{page_count: int, content_base64: string}
     */
    public function bake(string $contents, array $overlays, string $filename = 'document.pdf'): array
    {
        /** @var array{page_count: int, content_base64: string} $body */
        $body = $this->request()
            ->attach('file', $contents, $filename)
            ->post('/pdf/bake', ['overlays' => json_encode($overlays)])
            ->throw()
            ->json();

        return $body;
    }

    /**
     * Detect the interactive AcroForm fields in a PDF. Field rectangles are returned in PDF
     * user space (points, bottom-left origin) so the editor can position an input over each.
     *
     * @param  string  $contents  the raw PDF bytes
     * @return array{is_form: bool, fields: list<array{name: string, type: string, value: string, page_number: int, x: float, y: float, width: float, height: float, options: list<string>, readonly: bool, required: bool}>}
     */
    public function formFields(string $contents, string $filename = 'document.pdf'): array
    {
        /** @var array{is_form: bool, fields: list<array{name: string, type: string, value: string, page_number: int, x: float, y: float, width: float, height: float, options: list<string>, readonly: bool, required: bool}>} $body */
        $body = $this->request()
            ->attach('file', $contents, $filename)
            ->post('/pdf/form-fields')
            ->throw()
            ->json();

        return $body;
    }

    /**
     * Fill AcroForm field values onto a copy of a PDF, optionally flattening the widgets into
     * static content. The original bytes are never modified (the Golden Rule).
     *
     * @param  string  $contents  the raw PDF bytes
     * @param  array<string, string|bool>  $values  field name => value (booleans for checkboxes)
     * @return array{page_count: int, content_base64: string}
     */
    public function fillFormFields(string $contents, array $values, bool $flatten = false, string $filename = 'document.pdf'): array
    {
        /** @var array{page_count: int, content_base64: string} $body */
        $body = $this->request()
            ->attach('file', $contents, $filename)
            ->post('/pdf/form-fields/fill', [
                'values' => json_encode($values),
                'flatten' => $flatten ? 'true' : 'false',
            ])
            ->throw()
            ->json();

        return $body;
    }

    /**
     * OCR a (scanned) PDF into a searchable PDF plus the recognized text. Cached service-side
     * by content hash, so repeat calls for the same bytes are cheap. Uses the long timeout.
     *
     * @param  string  $contents  the raw PDF bytes
     * @return array{page_count: int, content_base64: string, text: string, language: string}
     */
    public function ocr(string $contents, string $language = 'eng', string $filename = 'document.pdf'): array
    {
        /** @var array{page_count: int, content_base64: string, text: string, language: string} $body */
        $body = $this->request($this->longTimeout)
            ->attach('file', $contents, $filename)
            ->post('/pdf/ocr', ['language' => $language])
            ->throw()
            ->json();

        return $body;
    }

    /**
     * Convert a PDF to an editable DOCX with the smart native-vs-scanned pipeline (OCR first
     * for scans). Returns the produced `.docx` (base64) plus which path ran. Uses the long
     * timeout. The original bytes are never modified (the Golden Rule).
     *
     * @param  string  $contents  the raw PDF bytes
     * @return array{source_type: string, ocr_applied: bool, page_count: int, content_base64: string}
     */
    public function exportDocx(string $contents, string $language = 'eng', string $filename = 'document.pdf'): array
    {
        /** @var array{source_type: string, ocr_applied: bool, page_count: int, content_base64: string} $body */
        $body = $this->request($this->longTimeout)
            ->attach('file', $contents, $filename)
            ->post('/pdf/export/docx', ['language' => $language])
            ->throw()
            ->json();

        return $body;
    }

    /**
     * Extract a document's text (per page + concatenated) for the AI/RAG layer. Native pages
     * are read from the text layer; scanned/mixed pages are OCR'd first. Uses the long timeout.
     *
     * @param  string  $contents  the raw PDF bytes
     * @return array{page_count: int, source_type: string, ocr_applied: bool, pages: list<array{page_number: int, text: string}>, text: string}
     */
    public function extractText(string $contents, string $language = 'eng', string $filename = 'document.pdf'): array
    {
        /** @var array{page_count: int, source_type: string, ocr_applied: bool, pages: list<array{page_number: int, text: string}>, text: string} $body */
        $body = $this->request($this->longTimeout)
            ->attach('file', $contents, $filename)
            ->post('/pdf/extract-text', ['language' => $language])
            ->throw()
            ->json();

        return $body;
    }

    /**
     * Embed a batch of texts into vectors (document chunks on ingest, or a query on search).
     * The vectors come back in the same order as the inputs.
     *
     * @param  list<string>  $texts
     * @return array{model: string, dimensions: int, embeddings: list<list<float>>}
     */
    public function embed(array $texts): array
    {
        /** @var array{model: string, dimensions: int, embeddings: list<list<float>>} $body */
        $body = $this->request($this->longTimeout)
            ->post('/ai/embed', ['texts' => $texts])
            ->throw()
            ->json();

        return $body;
    }

    /**
     * Ask the assistant a question grounded in the supplied context excerpts. The provider key
     * lives only in the Python service. Uses the long timeout (the model streams internally).
     * ``$provider`` (ollama | gemini | anthropic) overrides the service default for this call.
     *
     * @param  list<array{page_number: int, content: string}>  $contexts
     * @param  list<array{role: string, content: string}>  $history
     * @return array{answer: string, model: string}
     */
    public function chat(string $question, array $contexts, array $history = [], ?string $provider = null): array
    {
        /** @var array{answer: string, model: string} $body */
        $body = $this->request($this->longTimeout)
            ->post('/ai/chat', $this->withProvider([
                'question' => $question,
                'contexts' => $contexts,
                'history' => $history,
            ], $provider))
            ->throw()
            ->json();

        return $body;
    }

    /**
     * Summarize the supplied text (whole document or a single page). Uses the long timeout.
     * ``$provider`` overrides the service default backend for this call.
     *
     * @return array{summary: string, model: string}
     */
    public function summarize(string $text, ?string $scope = null, ?string $provider = null): array
    {
        /** @var array{summary: string, model: string} $body */
        $body = $this->request($this->longTimeout)
            ->post('/ai/summarize', $this->withProvider(['text' => $text, 'scope' => $scope], $provider))
            ->throw()
            ->json();

        return $body;
    }

    /**
     * Translate the supplied text into a target language (a natural-language name, e.g.
     * "French"). Uses the long timeout. ``$provider`` overrides the default backend for this call.
     *
     * @return array{translated: string, target_language: string, model: string}
     */
    public function translate(string $text, string $targetLanguage, ?string $provider = null): array
    {
        /** @var array{translated: string, target_language: string, model: string} $body */
        $body = $this->request($this->longTimeout)
            ->post('/ai/translate', $this->withProvider([
                'text' => $text,
                'target_language' => $targetLanguage,
            ], $provider))
            ->throw()
            ->json();

        return $body;
    }

    /**
     * Add the chosen LLM ``provider`` to an AI request body, omitting it when none is selected
     * (so the Python service falls back to its configured default).
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    protected function withProvider(array $payload, ?string $provider): array
    {
        if ($provider !== null && $provider !== '') {
            $payload['provider'] = $provider;
        }

        return $payload;
    }

    /**
     * Base request preconfigured with the service URL, shared secret, and a timeout. Pass an
     * explicit timeout for heavy calls (OCR, Word export) that run inside a queued job.
     */
    protected function request(?int $timeout = null): PendingRequest
    {
        return Http::baseUrl($this->baseUrl)
            ->withHeaders(['X-Pdf-Secret' => (string) $this->secret])
            ->timeout($timeout ?? $this->timeout)
            ->acceptJson();
    }
}
