# PDF service API

`pdf-service` is a Python FastAPI application that does everything requiring PDF byte access or
ML. It is stateless: it holds no database, no session and no user identity. Laravel sends the
bytes it wants processed and gets bytes or JSON back.

The single Laravel consumer is
[`App\Services\PdfServiceClient`](../app/Services/PdfServiceClient.php). Nothing else in the app
calls these endpoints directly.

---

## Conventions

| | |
|---|---|
| **Base URL** | `services.pdf.url` — `PDF_SERVICE_URL`, default `http://127.0.0.1:8001` |
| **Authentication** | `X-Pdf-Secret: <shared secret>` on **every** request, including `/health`. A mismatch returns `401`. |
| **File transfer** | Multipart upload. The service never reads Laravel's filesystem. |
| **Binary responses** | Base64 in a JSON field (`content_base64`, `image_base64`), so responses stay one content type. |
| **Coordinates** | Always PDF user space: points, origin bottom-left. |
| **Page numbers** | Always 1-based. |
| **Errors** | `401` bad secret · `422` invalid, corrupt or encrypted input (with a readable `detail`) · `500` unexpected |
| **Timeouts** | `PDF_SERVICE_TIMEOUT` (default 30s); OCR and export use `PDF_SERVICE_EXPORT_TIMEOUT` (default 300s) inside a queued job. |

Interactive OpenAPI docs are served at `/docs` when `PDF_SERVICE_ENV=local`.

---

## `GET /health`

Liveness and version. Secret-protected like everything else.

```json
{ "status": "ok", "service": "pdf-service", "version": "0.1.0" }
```

`PdfServiceClient::health(): array` · `PdfServiceClient::isHealthy(): bool`

---

## `POST /pdf/info`

Inspect a PDF: how many pages, how big, and whether it carries real text.

**Request** — multipart: `file`

**Response**

```json
{
  "page_count": 3,
  "pages": [{ "width": 595.28, "height": 841.89 }],
  "source_type": "native"
}
```

`source_type` is `native` (real text and vectors), `scanned` (image-only, needs OCR), `mixed`
(some of each) or `unknown`. It is stored on the document and decides the Word-export strategy.

`PdfServiceClient::info(string $contents, string $filename = 'document.pdf'): array`

---

## `POST /pdf/thumbnails`

Render page previews for the library card and the page rail.

**Request** — multipart: `file`, plus form fields

| Field | Type | Default | Notes |
|---|---|---|---|
| `pages` | CSV of 1-based page numbers | all pages | e.g. `1,3,5` |
| `dpi` | int | `96` | |

**Response**

```json
{
  "thumbnails": [
    { "page": 1, "width": 794, "height": 1123, "format": "png", "image_base64": "iVBORw0…" }
  ]
}
```

`PdfServiceClient::thumbnails(string $contents, array $pages = [], int $dpi = 96, string $filename = 'document.pdf'): array`

---

## `POST /pdf/pages`

Lossless page operations. Pages are **copied** with PyMuPDF's `insert_pdf` / `set_rotation` —
never re-rendered — so fidelity is exact.

**Request** — multipart: one or more repeated `files` parts, plus a `spec` form field holding a
JSON object discriminated on `op`.

### `organize` — reorder, rotate and delete in one pass

Exactly one input file. `pages` is the **desired final page list, in order**.

```json
{ "op": "organize", "pages": [ { "source": 3, "rotate": 90 }, { "source": 1, "rotate": 0 } ] }
```

- **Delete** a page by omitting it.
- **Reorder** by its position in the list.
- **Rotate** with `rotate`, a clockwise delta in multiples of 90 added to the page's current
  rotation.

### `split` — one document into several

Exactly one input file. Provide **either** `ranges` **or** `every`, not both.

```json
{ "op": "split", "ranges": [[1, 3], [4, 10]] }
{ "op": "split", "every": 5 }
```

Ranges are inclusive and 1-based.

### `merge` — several documents into one

Two or more input files, concatenated in the order they were uploaded.

```json
{ "op": "merge" }
```

**Response** — one output for `organize` and `merge`, several for `split`:

```json
{ "outputs": [ { "page_count": 2, "content_base64": "JVBERi0…" } ] }
```

`PdfServiceClient::pages(array $files, array $spec): array`

Results are persisted by `PageOperationService` as new versions (organize) or new documents
(split, merge). Inputs are never mutated.

---

## `POST /pdf/bake`

**The Golden Rule endpoint.** Draws overlays onto a copy of the input and returns a flattened
PDF. Content that carries no overlay is never re-encoded.

**Request** — multipart: `file`, plus an `overlays` form field holding a JSON array of:

```json
{ "type": "highlight", "page_number": 1, "z_index": 0, "order": 0, "payload": { } }
```

`payload` shapes are per type and validated in `services.pdf_bake`; geometry is in PDF user
space. The overlay types are listed in [Data model](data-model.md#overlay-types).

Baking is rotation-aware: the page rotation is temporarily set to 0 so overlays are drawn in
unrotated user space, then restored.

**Response**

```json
{ "page_count": 3, "content_base64": "JVBERi0…" }
```

`PdfServiceClient::bake(string $contents, array $overlays, string $filename = 'document.pdf'): array`

---

## `POST /pdf/form-fields`

Detect a PDF's interactive AcroForm fields.

**Request** — multipart: `file`

**Response**

```json
{
  "is_form": true,
  "fields": [
    {
      "name": "full_name", "type": "text", "value": "",
      "page_number": 1, "x": 72.0, "y": 640.0, "width": 220.0, "height": 18.0,
      "options": [], "readonly": false, "required": true
    }
  ]
}
```

`type` is one of `text`, `checkbox`, `radio`, `combobox`, `listbox`, `signature`, `button`,
`unknown`. Geometry is PDF user space, so the editor positions inputs with the same transform
it uses for overlays.

`PdfServiceClient::formFields(string $contents, string $filename = 'document.pdf'): array`

---

## `POST /pdf/form-fields/fill`

Set AcroForm values on a copy, optionally flattening the widgets into static page content.

**Request** — multipart: `file`, plus form fields

| Field | Type | Notes |
|---|---|---|
| `values` | JSON object, `name` → value | Booleans for checkboxes. Read-only fields are skipped. |
| `flatten` | bool | Bakes the appearances and drops the widgets. |

**Response** — `{ "page_count": 2, "content_base64": "JVBERi0…" }`

`PdfServiceClient::fillFormFields(string $contents, array $values, bool $flatten = false, string $filename = 'document.pdf'): array`

> The editor normally fills forms with `form_field` overlays plus a bake, which keeps the
> additive path. This endpoint is the alternative real-AcroForm primitive.

---

## `POST /pdf/ocr`

Make a scanned PDF searchable. Each page is rendered, OCR'd into a one-page searchable PDF
(image plus an invisible text layer) and concatenated.

**Request** — multipart: `file`, plus optional `language` (a Tesseract code, default `eng`)

**Response**

```json
{ "page_count": 4, "content_base64": "JVBERi0…", "text": "INVOICE …", "language": "eng" }
```

Idempotent — results are cached by `sha256(bytes + language)` under `PDF_OCR_CACHE_DIR`.
Missing language data returns `422`.

OCR uses **PyMuPDF's bundled Tesseract**, so there is no system Tesseract or Ghostscript
dependency — only a `tessdata/<lang>.traineddata` file.

`PdfServiceClient::ocr(string $contents, string $language = 'eng', string $filename = 'document.pdf'): array`

---

## `POST /pdf/export/docx`

The smart Word export. Picks its strategy from the detected source type:

| Source type | Strategy |
|---|---|
| `native` | `pdf2docx` — layout-aware reconstruction. Silently dropped runs are recovered into a labelled appendix. |
| `scanned` | OCR first, then rebuild with `python-docx` from the recognized spans. `pdf2docx` refuses image-only input, so this is the difference between a real document and an empty one. |
| `mixed` | Both, page by page: `pdf2docx` keeps the native pages' layout while OCR runs on only the image-only pages, whose text is folded back in. |

If `pdf2docx` fails outright, the export falls back to the same span-rebuilding renderer rather
than returning nothing.

**Request** — multipart: `file`, plus optional `language`

**Response**

```json
{ "source_type": "mixed", "ocr_applied": true, "page_count": 12, "content_base64": "UEsDBBQ…" }
```

`PdfServiceClient::exportDocx(string $contents, string $language = 'eng', string $filename = 'document.pdf'): array`

This call runs inside `ExportDocumentJob` with the longer export timeout.

---

## `POST /pdf/extract-text`

Per-page text for the RAG index. Native pages use their text layer; scanned and mixed pages are
OCR'd first (reusing `/pdf/ocr` and its cache), degrading to native text if language data is
absent.

**Request** — multipart: `file`, plus optional `language`

**Response**

```json
{
  "page_count": 2,
  "source_type": "native",
  "ocr_applied": false,
  "pages": [ { "page_number": 1, "text": "…" } ],
  "text": "…"
}
```

`PdfServiceClient::extractText(string $contents, string $language = 'eng', string $filename = 'document.pdf'): array`

---

## AI endpoints

These take **JSON** bodies rather than multipart. Laravel does retrieval over its own database
and sends the already-selected context, so the service stays stateless.

All four accept an optional `provider` — `"anthropic"`, `"gemini"` or `"ollama"`. Omitting it
uses the service's own `AI_PROVIDER` default. **The provider key lives only in
`pdf-service/.env`.**

### `POST /ai/embed`

```jsonc
// request
{ "texts": ["chunk one", "chunk two"] }
// response
{ "model": "hash-256", "dimensions": 256, "embeddings": [[0.01, …], [0.02, …]] }
```

Vectors come back in request order. The default provider is a deterministic local `hash`
function — no key, fully offline. `voyage` and `ollama` are the real-semantic options.

> Vectors from different models are not comparable. Switching providers requires re-indexing.

`PdfServiceClient::embed(array $texts): array`

### `POST /ai/chat`

```jsonc
// request
{
  "question": "What is the invoice total?",
  "contexts": [ { "page_number": 2, "content": "Total due: $4,200.00" } ],
  "history":  [ { "role": "user", "content": "…" } ],
  "provider": "ollama"
}
// response
{ "answer": "The invoice total is $4,200.00 (p. 2).", "model": "qwen2.5:3b" }
```

Limits: question ≤ 4,000 chars, ≤ 20 context chunks, ≤ 50 history turns, each ≤ 20,000 chars.
The answer is grounded in the supplied contexts and cites `(p. N)`. The call streams internally
to avoid HTTP timeouts but returns the complete answer.

`PdfServiceClient::chat(string $question, array $contexts, array $history = [], ?string $provider = null): array`

### `POST /ai/summarize`

```jsonc
// request   (text ≤ 100,000 chars; scope is a human label like "page 3")
{ "text": "…", "scope": "page 3", "provider": "gemini" }
// response
{ "summary": "…", "model": "gemini-2.0-flash" }
```

`PdfServiceClient::summarize(string $text, ?string $scope = null, ?string $provider = null): array`

### `POST /ai/translate`

```jsonc
// request
{ "text": "…", "target_language": "French", "provider": "ollama" }
// response
{ "translated": "…", "target_language": "French", "model": "qwen2.5:3b" }
```

Laravel only ever sends a target from a server-owned allow-list, so a tampered client value
cannot smuggle prompt instructions through the language name.

`PdfServiceClient::translate(string $text, string $targetLanguage, ?string $provider = null): array`

---

## Error handling on the Laravel side

Every `PdfServiceClient` method throws on a failed call, and each caller decides what the user
sees — a validation error on the component, a `failed` export job row, or a flash message.
Failures are distinguished rather than lumped together: a `ConnectionException` becomes "the PDF
processing service is unavailable right now", while a `422` carrying
`PDF is encrypted/password-protected.` becomes "this PDF is password-protected", so the user is
told what to actually do.

Degradation is graceful where it can be. A failed thumbnail render is non-fatal — the document
is still created, just without a cover image — and with no LLM configured everything except
chat, summarize and translate keeps working. Upload itself does require the service, since
`POST /pdf/info` supplies the page count and source type.

In tests the whole service is replaced with an HTTP fake, so the Laravel suite runs offline. See
[Testing](testing.md).
