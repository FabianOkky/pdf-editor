# Phase 1 — Document Management (upload, library, view)

**Status:** ✅ Done
**Depends on:** Phase 0
**Last updated:** 2026-06-15 (built & tested)

> **📎 Context to load — read ONLY these (saves tokens):**
> `STATUS.md` (small; what's done) · **this file** · Phase 0's Handoff notes ·
> `ARCHITECTURE.md` §3, §4, §6, §7 only.
> Skip `README.md` and other phase files unless a Handoff note below points you there.
>
> **Golden Rule (always):** never regenerate the PDF — edits are non-destructive overlays.
> Phase 1 = upload + library + PDF.js viewer (read-only).

## Goal

A logged-in user can **upload PDFs, see them in a library/dashboard, open one, and view
it page-by-page in the browser** with PDF.js. This is the home base of the app.

## Scope

**In:**
- Upload flow (Livewire + Flux file upload) with validation (mime, size, page cap) per
  `ARCHITECTURE.md` §6. Store original immutably; create `documents` row.
- On upload, call Python `POST /pdf/info` to fill `page_count`, page sizes, and
  `source_type` (native vs scanned detection — heuristic: ratio of extractable text).
- Library/dashboard: grid or list of the user's documents with thumbnail, title, page
  count, date; rename, delete (soft delete), download original.
- Document viewer page: PDF.js rendering, page navigation, zoom, thumbnail rail.
- Per-document policy: a user only sees/acts on their own documents.

**Out:** editing, page ops, export, AI (later phases). Viewer is read-only here.

## Tasks

- [x] Python: implement `POST /pdf/info` (PyMuPDF) → `{page_count, pages:[{width,height}], source_type}`.
      Add pytest with native + scanned (+ mixed) fixture PDFs (generated in-memory in conftest).
- [x] Python: implement `POST /pdf/thumbnails` (render pages to base64 PNG at a configurable dpi).
      pytest.
- [x] Extend `PdfServiceClient` with `info()` and `thumbnails()`.
- [x] Upload integrated into `Documents/Index` (Flux modal): validated upload → call `info()`
      → store immutably → persist. Progress + errors shown.
- [x] Livewire `Documents/Index` (library): list current user's docs, rename, soft-delete,
      download original. Empty state.
- [x] Livewire `Documents/Show` (viewer): mounts PDF.js from `resources/js/pdf-editor/`,
      renders pages, navigation, zoom, client-side thumbnail rail.
- [x] Add PDF.js to the frontend build (`pdfjs-dist` v6) and a small viewer module; wired with Vite.
- [x] `DocumentPolicy` (auto-discovered); ownership enforced in components + `can:` route middleware.
- [x] Routes (named) for index/show/file/download/thumbnail; added "Documents" nav entry in the sidebar.
- [x] Pest feature tests: upload (valid/invalid/too-large/page-cap/service-down), library listing
      scoped to user, authorization (cannot open/rename/delete another user's doc), rename, delete,
      viewer + file routes. Python mocked with `Http::fake`.
- [x] `php artisan test --compact` + `pytest` green; Pint + ruff/black; larastan L7 green.

## Acceptance criteria

- User uploads a PDF and immediately sees it in their library with correct page count + thumbnail.
- Opening a document renders it accurately with PDF.js; navigation/zoom work.
- Another user cannot access the document (policy enforced + tested).
- Invalid uploads are rejected with clear messages.

## Tests required

- Pest: upload validation, ownership scoping, authorization, rename/delete, viewer route loads.
- pytest: `/pdf/info` + `/pdf/thumbnails` on native and scanned fixtures.

## Handoff notes — DONE 2026-06-15

**What got built**

- **Python service** (`pdf-service/`): `routers/pdf.py` mounts a `/pdf` router (secret-protected
  at the router level). `services/pdf_info.py` (`analyze_pdf`) and `services/pdf_thumbnails.py`
  (`render_thumbnails`) share `services/pdf_document.py::open_pdf` (turns corrupt/encrypted PDFs
  into `ValueError` → 422). Schemas in `schemas/pdf.py`. `python-multipart` added to
  `requirements.txt` (already installed in the venv). 15 pytest tests; fixtures (native/scanned/
  mixed) are built in-memory in `tests/conftest.py` (no committed binaries). ruff + black clean.
- **Endpoint shapes** (also in `ARCHITECTURE.md §4`):
  - `POST /pdf/info` (multipart `file`) → `{ page_count:int, pages:[{width:float,height:float}],
    source_type:"native"|"scanned"|"mixed"|"unknown" }`. Sizes in PDF points.
  - `POST /pdf/thumbnails` (multipart `file`; form `pages`=CSV 1-based optional, `dpi`=int=96) →
    `{ thumbnails:[{page,width,height,format:"png",image_base64}] }`.
  - `source_type` heuristic: page is "native" if ≥16 non-whitespace text chars; all⇒native,
    none⇒scanned, mixed⇒mixed, 0 pages⇒unknown.
- **Laravel client**: `PdfServiceClient::info($contents,$filename)` and
  `::thumbnails($contents,$pages=[],$dpi=96,$filename)` (multipart `attach`). Pest covers both
  with `Http::fake`.
- **Data/flow**: upload lives in `Documents/Index` (Flux modal). `save()` reads bytes → calls
  `info()` **before** storing → enforces `services.pdf.max_pages` → stores immutably via
  `$upload->store('documents','pdfs')` → creates the `Document` via `auth()->user()->documents()`
  (new `User::documents()` HasMany) → best-effort cover thumbnail. The original is never rewritten.
- **Thumbnails**: cover (page 1 @ 96 dpi) saved to the **private `pdfs` disk** at
  `thumbnails/<uuid>.png`; path stored in `Document.meta['thumbnail_path']`; full page geometry
  in `Document.meta['pages']`. Served by `DocumentFileController@thumbnail` (404 if absent).
- **Routes** (`routes/web.php`, all under `auth,verified`): `documents.index`, `documents.show`
  (`{document}` route-model-bound), and `DocumentFileController` GET routes `documents.file`
  (inline), `documents.download` (attachment), `documents.thumbnail` — the file routes are
  guarded by `can:view|download,document` middleware. Sidebar has a "Documents" entry.
- **Policy**: `DocumentPolicy` (auto-discovered, no manual registration) — every ability is just
  ownership (`user_id`). Components call `$this->authorize(...)`; routes use `can:`.
- **Config**: `services.pdf.max_upload_mb` (25) + `max_pages` (500), env-overridable
  (`PDF_MAX_UPLOAD_MB`, `PDF_MAX_PAGES`). `AppServiceProvider::configureFileUploads()` raises
  Livewire's global temp-upload cap to match (was 12 MB). No `config/livewire.php` published.

**Viewer module (for Phase 3 — the overlay editor)**

- `resources/js/pdf-editor/viewer.js` exports `pdfViewer({url,pageCount})`, registered as an
  Alpine component in `resources/js/app.js` on `alpine:init`. `resources/views/livewire/
  documents/show.blade.php` mounts it via `x-data="pdfViewer(...)"` inside a `wire:ignore`
  container (keeps the canvas out of Livewire morphing). Controls bind to `next/prev/goTo/
  zoomIn/zoomOut/resetZoom`; state `currentPage/pageCount/scale/loading/error`.
- It deliberately keeps `this.viewport` after each render and exposes `toPdfPoint(x,y)` /
  `toScreenPoint(x,y)` (thin wrappers over `resources/js/pdf-editor/coords.js`
  `screenToPdf`/`pdfToScreen`, which call PDF.js `convertToPdfPoint`/`convertToViewportPoint`).
  **Phase 3 should build the overlay layer on top of `renderPage()` and store geometry in PDF
  points using these helpers** (ARCHITECTURE §3). The main canvas is `$refs.canvas`; the
  client-rendered thumbnail rail is built imperatively into `$refs.thumbs`.
- `pdfjs-dist` v6; the worker is imported as `pdf.worker.min.mjs?url` and Vite emits it as a
  separate asset (Tailwind scans `resources/js` via a new `@source '../js'` in `app.css` because
  the rail's classes are applied from JS). Run `npm run build` (or `npm run dev`) after JS edits.

**Gotchas / decisions**
- Calling `info()` before storing means an over-cap or unreadable PDF leaves **nothing** on disk
  or in the DB (tested). Service-unreachable ⇒ friendly error on the `file` field, no document.
- larastan runs at **level 7**; use `vendor/bin/phpstan analyse --memory-limit=1G` (128 MB OOMs).
- Fixed latent Phase 0 issues to make `composer test` green: model `@property` referenced a
  non-existent `Illuminate\Support\CarbonImmutable` (→ `Carbon\CarbonImmutable`); redundant `??`;
  empty `UserFactory::withTwoFactor()` (now sets 2FA columns — unblocks the skipped 2FA login
  test); repo-wide Pint EOF/import fixes.
- The 1 skipped Pest test is the Fortify 2FA login redirect (skips unless 2FA is enabled) — same
  skip as Phase 0, not a regression.

**Not done here (future phases)**: editing/overlays, page ops, export, AI; trashed-document
restore/purge UI + orphaned-file cleanup; lazy-loading pdfjs off non-viewer pages (Phase 7 polish).
