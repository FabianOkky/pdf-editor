# Architecture & Technical Decisions

This document is binding. When in doubt during any phase, follow what is written here.
Communication with the user is in Indonesian; **all code, comments, docs, identifiers,
and these plan files are in English.**

---

## 0. The Golden Rule (fidelity)

> **Never regenerate the whole PDF. Preserve the original; apply changes as an additive
> overlay layer. Editing is non-destructive by default.**

Why: a PDF stores positioned glyphs, not a reflowable document. Re-encoding the whole
file is what makes other tools corrupt fonts, lines and layout. We avoid that.

Concretely:

- The **original uploaded file is immutable**. We never overwrite it.
- Every edit is stored as **structured JSON overlay operations** (see data model),
  not as a re-rendered PDF.
- A flattened/exported PDF is produced **on demand** by baking overlays onto a copy of
  the original (PyMuPDF), and saved as a **new version** — the original stays intact.
- "Edit existing text" = draw a whiteout rectangle over the old text + place a new text
  box on top (overlay). True in-place content-stream text editing is a **stretch goal**
  (Phase 3 notes) and must reuse the original embedded font when attempted.

---

## 1. System diagram

```
                ┌──────────────────────────────────────────┐
   Browser      │  Livewire/Flux UI                         │
                │  PDF.js (render) + overlay edit layer (JS) │
                └───────────────┬──────────────────────────┘
                                │  HTTP (Livewire) / file up-download
                ┌───────────────▼──────────────────────────┐
   Laravel 13   │  Auth (Fortify) · Document library · DB   │
                │  Storage · Queue/Jobs · Orchestration     │
                │  PdfServiceClient (HTTP client to Python) │
                └───────────────┬──────────────────────────┘
                                │  REST + shared-secret header (localhost)
                ┌───────────────▼──────────────────────────┐
   Python       │  FastAPI                                  │
   (FastAPI)    │  PyMuPDF (page ops, redaction, bake)      │
                │  ocrmypdf/Tesseract (OCR)                 │
                │  pdf2docx / LibreOffice (Word export)     │
                │  LLM + embeddings glue (AI features)      │
                └───────────────────────────────────────────┘
```

Display rendering is **client-side (PDF.js)** for speed and fidelity. The Python service
is for operations that must touch PDF bytes or run ML.

---

## 2. Repository layout

The Laravel app lives at the repo root. The Python service lives in a sibling folder so
it can be deployed and versioned independently but stays in the same repo.

```
pdf-editor/
├── app/ … (Laravel)
├── resources/js/pdf-editor/      # PDF.js viewer + overlay editor (added in Phase 1/3)
├── pdf-service/                  # Python FastAPI microservice (added in Phase 0)
│   ├── app/
│   │   ├── main.py               # FastAPI app + routes
│   │   ├── routers/              # one module per capability (pages, overlay, ocr, export, ai)
│   │   ├── services/             # PyMuPDF / OCR / docx / ai logic
│   │   ├── schemas/              # pydantic request/response models
│   │   └── core/                 # config, auth (shared secret), logging
│   ├── tests/                    # pytest
│   ├── pyproject.toml            # deps (managed with uv or pip)
│   └── README.md                 # how to run the service
└── .claude/plan/                 # this plan
```

> Do not create new top-level folders beyond `pdf-service/` and `resources/js/pdf-editor/`
> without updating this file.

---

## 3. Data model (Laravel migrations)

Create incrementally; Phase 0 sets up `documents` + `document_versions`. Later phases add
the rest. Use `php artisan make:model -mf` and factories.

- **documents**
  - `id`, `user_id` (FK), `title`, `original_filename`, `disk`, `path` (immutable original),
    `page_count`, `size_bytes`, `mime`, `source_type` enum(`native`,`scanned`,`mixed`,`unknown`),
    `status` enum(`ready`,`processing`,`failed`), `meta` json, timestamps, softDeletes.
- **document_versions** (non-destructive history of flattened outputs)
  - `id`, `document_id` (FK), `version_number`, `path`, `label`, `created_by`, timestamps.
- **document_overlays** (the edit operations — added Phase 3)
  - `id`, `document_id` (FK), `page_number`, `type` enum(`text`,`whiteout`,`highlight`,
    `underline`,`strike`,`shape`,`freehand`,`image`,`signature`,`form_field`),
    `payload` json (geometry in PDF user-space + style + content), `z_index`, `order`,
    timestamps. **This table is the source of truth for an edited document.**
- **export_jobs** (added Phase 5)
  - `id`, `document_id`, `format` enum(`pdf`,`docx`), `engine`, `status`, `result_path`,
    `error`, timestamps.
- **ocr_jobs** (added Phase 6, or alongside Phase 5)
  - `id`, `document_id`, `status`, `result_path`, `text_path`, `language`, timestamps.
- **ai_conversations / ai_messages** (added Phase 6)
  - conversation: `id`, `document_id`, `user_id`, `title`, timestamps.
  - message: `id`, `conversation_id`, `role` enum(`user`,`assistant`,`system`),
    `content`, `tokens`, `meta` json, timestamps.
- **document_chunks** (added Phase 6, for RAG)
  - `id`, `document_id`, `page_number`, `chunk_index`, `content`, `embedding` (vector or
    json), timestamps. (Vector store choice decided in Phase 6.)

Coordinates: store overlay geometry in **PDF user space (points, origin bottom-left)** so
Python/PyMuPDF can bake them deterministically regardless of zoom. The JS layer converts
between screen and PDF coordinates.

---

## 4. Laravel ↔ Python contract

- Base URL from config `services.pdf.url` (env `PDF_SERVICE_URL`, default
  `http://127.0.0.1:8001`).
- Every request carries header `X-Pdf-Secret: <shared secret>` (env `PDF_SERVICE_SECRET`).
  Python rejects mismatches. Service binds to localhost only.
- A single Laravel client wraps all calls: `App\Services\PdfServiceClient` (created Phase 0).
- File transfer: Laravel sends the file (multipart) or a path/URL the service can read.
  Start with multipart upload to keep services decoupled.

Planned endpoints (add as phases need them; keep this list updated):

| Method & path | Added in | Purpose |
|---|---|---|
| `GET /health` | 0 | liveness + version |
| `POST /pdf/info` | 1 | page count, page sizes, native-vs-scanned detection |
| `POST /pdf/thumbnails` | 1/2 | per-page preview images |
| `POST /pdf/pages` | 2 | reorder / rotate / delete / split / merge → new PDF |
| `POST /pdf/bake` | 3 | apply overlay JSON onto original → flattened PDF |
| `POST /pdf/form-fields` | 4 | detect AcroForm fields (name, type, value, page, rect, options) |
| `POST /pdf/form-fields/fill` | 4 | set AcroForm values (+ optional flatten) → new PDF |
| `POST /pdf/ocr` | 5/6 | OCR → searchable PDF + extracted text |
| `POST /pdf/export/docx` | 5 | smart PDF→DOCX (native path or OCR-first path) |
| `POST /pdf/extract-text` | 6 | text + layout for AI/RAG |
| `POST /ai/*` | 6 | chat / summarize / translate / extract (may live Laravel-side; TBD) |

Request/response bodies are defined with pydantic in the service and mirrored by typed
DTOs/arrays on the Laravel side. Document each new endpoint here when you add it.

**Implemented in Phase 1** (both take the PDF as a multipart `file`; corrupt/encrypted ⇒ 422):

- `POST /pdf/info` → `{ "page_count": int, "pages": [{ "width": float, "height": float }, …],
  "source_type": "native"|"scanned"|"mixed"|"unknown" }`. Sizes are PDF points. Client:
  `PdfServiceClient::info(string $contents, string $filename)`.
- `POST /pdf/thumbnails` (form fields: `pages` = optional CSV of 1-based page numbers, omit for
  all; `dpi` = int, default 96) → `{ "thumbnails": [{ "page": int, "width": int, "height": int,
  "format": "png", "image_base64": string }, …] }`. Client:
  `PdfServiceClient::thumbnails(string $contents, array $pages = [], int $dpi = 96, string $filename)`.

**Implemented in Phase 2** — `POST /pdf/pages` (lossless page ops; PyMuPDF `insert_pdf` /
`set_rotation` — pages copied, never re-rendered). Multipart: repeated `files` part(s) + a JSON
`spec` form field. Response: `{ "outputs": [{ "page_count": int, "content_base64": string }, …] }`
(one for organize/merge, many for split). Invalid input ⇒ 422. Client:
`PdfServiceClient::pages(array $files, array $spec): array`.

- `{"op":"organize","pages":[{"source":<1-based>,"rotate":<deg≡0 mod 90>}, …]}` — final page
  list, in order. Delete = omit; reorder = order; rotate = delta on the page's current rotation.
  Exactly 1 file. Backs reorder/rotate/delete (the contract table's separate ops are folded here).
- `{"op":"split","ranges":[[s,e], …]}` **xor** `{"op":"split","every":N}` — 1-based inclusive
  ranges / N-page chunks → one output each. Exactly 1 file.
- `{"op":"merge"}` — concatenate the uploaded `files` in order. ≥2 files.

Results are persisted by `App\Services\PageOperationService` as new `document_versions`
(organize/restore) or new `documents` (split/merge); originals are never mutated. The
"active" bytes shown = latest version, else original (`Document::activePath()` /
`activePageCount()`). See `phase-2-page-operations.md` Handoff notes for the full data model.

**Implemented in Phase 3** — `POST /pdf/bake` (overlay → flattened PDF; overlays drawn on an
in-memory copy, never re-rendering unedited content — the Golden Rule; rotation-aware via
`set_rotation(0)`/restore). Multipart: a single `file` part + an `overlays` JSON-array form
field of `{type, page_number, z_index, order, payload}` (geometry in PDF user space, bottom-left
origin; per-type `payload` shapes in `phase-3-overlay-editor.md` Handoff). Response:
`{ "page_count": int, "content_base64": string }`. Invalid input ⇒ 422. Client:
`PdfServiceClient::bake(string $contents, array $overlays, string $filename): array`. Persisted
by `PageOperationService::bake()` as a new `document_version`, after which the overlay layer is
cleared. See `phase-3-overlay-editor.md` Handoff notes for the overlay schema.

**Implemented in Phase 4** — AcroForm support + two new bakeable overlay types. Both endpoints
take the PDF as a multipart `file`; geometry is in **PDF user space (bottom-left origin)**.

- `POST /pdf/form-fields` → `{ "is_form": bool, "fields": [{ "name", "type"
  (text|checkbox|radio|combobox|listbox|signature|button|unknown), "value", "page_number",
  "x", "y", "width", "height", "options": [...], "readonly", "required" }, …] }`. Client:
  `PdfServiceClient::formFields(string $contents, string $filename): array`.
- `POST /pdf/form-fields/fill` (form fields: `values` = JSON object name→value, booleans for
  checkboxes; `flatten` = bool) → `{ "page_count", "content_base64" }`. Sets widget values
  (read-only fields skipped); `flatten` bakes appearances and drops the widgets
  (`Document.bake(widgets=True)`). Original bytes never modified. Client:
  `PdfServiceClient::fillFormFields(string $contents, array $values, bool $flatten, string $filename)`.
- **`/pdf/bake` now flattens** `signature` (drawn as an image) and `form_field` (the value as
  text, or an "X" for a checked box) overlays. The **editor fills forms via `form_field`
  overlays + bake** (additive, Golden Rule); the interactive `fill` endpoint is the alternative
  real-AcroForm primitive. See `phase-4-forms-signatures.md` Handoff for payload shapes + the
  per-user `signatures` table (reusable signatures stored inline as PNG data URLs).

**Implemented in Phase 5** — OCR + smart Word export. Both take the PDF as a multipart `file` and
an optional `language` form field (Tesseract code, default `eng`). OCR uses **PyMuPDF's bundled
Tesseract** (no system Tesseract/Ghostscript — only a `tessdata/<lang>.traineddata` data file;
`PDF_TESSDATA_PREFIX`). The original bytes are never modified (the Golden Rule).

- `POST /pdf/ocr` → `{ "page_count": int, "content_base64": <searchable PDF>, "text": str,
  "language": str }`. Renders each page, OCRs it into a one-page searchable PDF (image + invisible
  text layer), concatenates them, and extracts the recognized text. **Idempotent — cached by
  `sha256(bytes+language)`** under `PDF_OCR_CACHE_DIR`. Client: `PdfServiceClient::ocr(string
  $contents, string $language = 'eng', string $filename): array`. Missing language data ⇒ 422.
- `POST /pdf/export/docx` → `{ "source_type": str, "ocr_applied": bool, "page_count": int,
  "content_base64": <docx> }`. **Smart pipeline:** `native` → `pdf2docx` (layout-aware);
  `scanned`/`mixed` → OCR first, then the recognized text blocks are laid out as a `.docx` via
  python-docx (pdf2docx itself refuses scanned input, so this is the edge over naive converters).
  Client: `PdfServiceClient::exportDocx(string $contents, string $language = 'eng', string
  $filename): array`. Heavy calls use a longer `services.pdf.export_timeout` (300s).
- **Async on the Laravel side:** `export_jobs` (+ `ExportJob` model, `ExportJobStatus`/`ExportFormat`
  enums) tracks each run; `ExportDocumentJob` (queued) → `ExportService::process()` transitions
  queued → processing → completed/failed and stores the `.docx` under `exports/<uuid>.docx` on the
  document's disk. Download is document-scoped: `documents.exports.download` (`can:view`, scope-bound,
  404 until completed). The viewer's "Export to Word" modal polls status and reveals the download.

**Implemented in Phase 6** — AI assistant. Text extraction is multipart; `/ai/*` take JSON bodies and
require the shared secret. **All model calls live in the Python service** (provider key only in
`pdf-service/.env`); Laravel orchestrates + does retrieval over its own DB. LLM = Claude
`claude-opus-4-8` (streamed internally to dodge timeouts, full text returned).

- `POST /pdf/extract-text` → `{ page_count, source_type, ocr_applied, pages:[{page_number, text}], text }`.
  Native per-page text; scanned/mixed are OCR'd first (reuses `/pdf/ocr`), degrading to native text when
  tessdata is absent. Client: `PdfServiceClient::extractText($contents, $language, $filename)`.
- `POST /ai/embed` → `{ model, dimensions, embeddings: [[float]] }` for a batch of texts. Provider is
  config (`AI_EMBEDDING_PROVIDER`): default `hash` (deterministic local, no key) or `voyage`.
  Client: `PdfServiceClient::embed($texts)`.
- `POST /ai/chat` (body `{ question, contexts:[{page_number, content}], history:[{role, content}] }`)
  → `{ answer, model }`, grounded with `(p. N)` citations. Client: `PdfServiceClient::chat(...)`.
- `POST /ai/summarize` (`{ text, scope? }`) → `{ summary, model }`. `POST /ai/translate`
  (`{ text, target_language }`) → `{ translated, target_language, model }`. Clients:
  `PdfServiceClient::summarize()/translate()`.
- **Vector store = portable JSON + in-process cosine, NOT pgvector** (the dev/CI Postgres lacks the
  `vector` extension). `document_chunks.embedding` is a JSON float array; `App\Services\RagService` ranks
  chunks by cosine in PHP. `ChunkingService` (per-page overlapping chunks) + `RagService` (ingest/retrieve,
  auto re-index on active-bytes change) + `AiAssistantService` (chat/summarize/translate) + Livewire
  `Documents\AiAssistant` panel. `ai_conversations` / `ai_messages` persist chat. AI output stays in the
  panel — never written onto the PDF. Per-user `RateLimiter` + `AI_MAX_INPUT_CHARS` cap.

---

## 5. Async & jobs

Heavy work (OCR, Word export, AI batch, large bake) runs through **Laravel queued jobs**
that call the Python service and update the relevant `*_jobs` row + broadcast/poll for UI
status. Start with the `database` queue driver. Keep synchronous calls only for fast ops
(info, thumbnails, small bakes).

---

## 6. Security & limits

- Validate uploads: mime `application/pdf`, max size (start 25 MB, configurable), page
  cap (configurable). Reject encrypted PDFs with a clear message (or ask for password).
- All documents scoped to `auth()->id()`; enforce with policies. A user can only touch
  their own documents.
- Sanitize filenames; never trust client paths. Store under hashed paths.
- Python service: localhost bind + shared secret; never expose publicly without auth.
- Treat uploaded PDFs as untrusted input (PDFs can carry exploits) — process in the
  isolated Python service, disable JS in any rendering, keep libraries patched.

---

## 7. Testing policy

- **Laravel:** Pest feature tests for every Livewire component/flow; use factories.
  `php artisan test --compact`.
- **Python:** pytest for every service function and FastAPI route; use small fixture PDFs
  committed under `pdf-service/tests/fixtures/`.
- **Contract:** mock the Python service in Laravel tests (HTTP fakes); have at least one
  end-to-end happy-path test per major feature.
- Run `vendor/bin/pint --dirty --format agent` after PHP changes; format Python with
  `ruff`/`black` (decided in Phase 0).

---

## 8. Open decisions (resolve when the phase arrives, then record here)

- **Resolved 2026-06-22:** LLM = **Anthropic Claude `claude-opus-4-8`**, called from the Python
  service (`/ai/*`); key only in `pdf-service/.env`. Embeddings provider-abstracted (`hash` default,
  Voyage optional). See STATUS decisions log + `phase-6-ai-assistant.md` handoff.
- **Resolved 2026-06-22:** Vector store for RAG = **portable JSON embeddings + in-process cosine**,
  NOT pgvector — the dev/CI Postgres has no `vector` extension available (`pg_available_extensions`
  empty for it). This is the plan's allowed in-process fallback; upgrade path noted in the
  `create_document_chunks` migration.
- **Resolved 2026-06-20:** Word export uses **PyMuPDF's bundled Tesseract** for OCR (no system
  Tesseract/Ghostscript; ocrmypdf dropped) + **pdf2docx** for native conversion / python-docx for
  the OCR-text path. **Second engine (LibreOffice headless) SKIPPED** — heavy and Windows-hostile;
  the `export_jobs.engine` column leaves room to add it behind a flag later if fidelity demands it.
- **Resolved 2026-06-14:** Primary DB = **PostgreSQL 18** (dev `pdf_editor`, tests
  `pdf_editor_test`). This makes **pgvector** the natural RAG vector store for Phase 6.
- **Resolved 2026-06-13:** Python deps = `requirements.txt` + venv (not `uv`). Python 3.14
  is used (PyMuPDF/FastAPI verified to install). **Open:** if Phase 5/6 OCR/docx libs lack
  3.14 wheels, recreate the venv with Python 3.12 (steps in `phase-0-foundation.md` §F).
- Deploy target for the Python service in production (Phase 7).
