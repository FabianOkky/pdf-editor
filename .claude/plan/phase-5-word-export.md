# Phase 5 — Smart Word Export (PDF → DOCX)

**Status:** ✅ Done (Session 2026-06-20)
**Depends on:** Phase 1 (documents). **Owns the OCR pipeline now shared with Phase 6.**
**Last updated:** 2026-06-20

> **📎 Context to load — read ONLY these (saves tokens):**
> `STATUS.md` (small; what's done — check if OCR exists yet) · **this file** ·
> `ARCHITECTURE.md` §3, §4, §5, §7, §8 only.
> Skip `README.md` and other phase files unless a Handoff note below points you there.
>
> **Golden Rule (always):** never regenerate the PDF — edits are non-destructive overlays.
> **Headline feature.** Edge = smart native-vs-scan pipeline; UI must be honest: output is
> *editable and close*, not pixel-identical.

## Goal

A user exports any PDF to an editable `.docx`. The system auto-detects whether the PDF is
native (text) or scanned (image) and routes through the right pipeline, running OCR first
when needed. Runs as an async queued job with status feedback.

## Pipeline

```
PDF ──▶ detect source_type (native / scanned / mixed)
        │
        ├─ native  ──▶ pdf2docx (layout-aware) ──▶ .docx
        │
        └─ scanned ──▶ OCR (ocrmypdf → searchable PDF) ──▶ pdf2docx ──▶ .docx
                         (the step most free tools skip)
```

Optional second engine (LibreOffice headless) for comparison — decide in this phase
(`ARCHITECTURE.md` §8). Keep it behind a flag if included.

## Scope

**In:**
- `source_type` detection (reuse/refine the Phase 1 heuristic).
- OCR step (ocrmypdf/Tesseract) producing a searchable PDF + extracted text. **This OCR
  capability is shared with Phase 6** — whoever builds it first owns `/pdf/ocr`; update STATUS.
- `pdf2docx` conversion; assemble final `.docx`.
- Async export via Laravel queued job + `export_jobs` row; UI shows progress + download
  when ready; honest "best-effort" copy.

**Out:** AI-based reconstruction (Phase 6 can later improve scanned exports).

## Tasks

- [x] Add Python deps: `pdf2docx` (OCR uses **PyMuPDF's bundled Tesseract** — no `ocrmypdf`, no
      system Tesseract/Ghostscript; only a `tessdata/<lang>.traineddata` file). Documented in
      `pdf-service/README.md` + `tessdata/README.md`.
- [x] Python: `POST /pdf/ocr` → searchable PDF + text (idempotent; cached by `sha256(bytes+lang)`).
      pytest with a `scanned_text_pdf` fixture (real OCR test skips when tessdata absent).
- [x] Python: `POST /pdf/export/docx` → smart pipeline, returns `.docx`. pytest for native
      (always) + scanned (real OCR, skip-aware) + routing (faked OCR, always) — valid docx, text present.
- [x] Extend `PdfServiceClient` with `ocr()` and `exportDocx()` (long `export_timeout`).
- [x] `ExportDocumentJob` (queued) + `export_jobs` model/migration + `ExportService`; status transitions.
- [x] Livewire: "Export to Word" modal with progress (`wire:poll`), error handling, download.
- [x] UX copy that sets honest expectations about fidelity.
- [x] Pest feature tests: trigger export, job lifecycle, download, authorization (mock Python).
- [x] pytest: OCR + both export paths.
- [x] Tests green; Pint + ruff/black.

## Acceptance criteria

- A native PDF exports to an editable `.docx` whose text and rough layout match.
- A scanned PDF is OCR'd first, then exported to a `.docx` containing the recognized text
  (proving the pipeline beats naive converters that return empty/garbled output).
- Export runs async with clear status and a working download.

## Tests required

- pytest: `/pdf/ocr` on scanned fixture; `/pdf/export/docx` on native + scanned fixtures.
- Pest: export job lifecycle, download, authorization.

## Handoff notes

**Done.** Smart PDF→DOCX export, fully tested. Full gate green: Pint, larastan (L7, 0 errors),
Pest (115 pass / 1 skip), pytest (65), Vite build.

### OCR engine & install (Phase 6 reuses this)
- **Engine = PyMuPDF's *bundled* Tesseract** (`Pixmap.pdfocr_tobytes`). The PyPI MuPDF wheel has
  Tesseract compiled in, so there is **no system Tesseract or Ghostscript install** — you only need
  the language data file. `ocrmypdf` was **not** used (heavy + Windows-hostile).
- **tessdata**: `pdf-service/tessdata/<lang>.traineddata` (default `eng`). **Gitignored** (4 MB blob);
  one-line download in `pdf-service/tessdata/README.md`. Configurable via `PDF_TESSDATA_PREFIX`,
  `PDF_OCR_LANGUAGE`, `PDF_OCR_DPI` (default 200), `PDF_OCR_CACHE_DIR`.
- **Availability probe**: `app/services/pdf_ocr.py::ocr_available()` (checks the `.traineddata` file).
  pytest marks the *real-OCR* tests `skipif(not ocr_available())`; native export + routing tests run
  unconditionally (OCR faked via monkeypatch). On this machine tessdata is present → nothing skips.

### `/pdf/ocr` contract (Phase 6: use this for AI text extraction)
- `POST /pdf/ocr` (multipart `file`, optional `language`) → `{ page_count, content_base64 (searchable
  PDF: image + invisible text layer), text, language }`. **Idempotent — cached by `sha256(bytes+lang)`**
  under `.ocr_cache/` (gitignored, best-effort). Client: `PdfServiceClient::ocr($contents, $lang='eng',
  $filename)`. Unreadable PDF or missing tessdata ⇒ 422.
- **Phase 6 note:** the planned `/pdf/extract-text` may not need a new endpoint — `/pdf/ocr` already
  returns `text`, and `pdf_info.analyze_pdf()` gives `source_type`. For native PDFs you can pull text
  directly with PyMuPDF `get_text` (cheaper than OCR); only OCR the scanned/mixed ones.

### Export pipeline & fidelity limits (surface in UI — already done)
- `POST /pdf/export/docx` → `{ source_type, ocr_applied, page_count, content_base64 (docx) }`.
  **native → `pdf2docx`** (layout-aware); **scanned/mixed → OCR first, then text blocks → `.docx`**
  via python-docx. Why hybrid: **pdf2docx refuses scanned/invisible-text PDFs** ("Words count: 0 …
  not supported") and emits an empty file — so we feed it only native PDFs and build the scanned DOCX
  ourselves. `engine` recorded as `pdf2docx` (native) or `ocr+python-docx` (scanned).
- **Fidelity is best-effort** — the viewer modal says so plainly (text + rough layout, not pixel-perfect;
  scanned docs note OCR was used). Don't promise more.
- **Known limitation:** **mixed** PDFs are OCR'd wholesale (native pages get re-OCR'd, losing crisp
  vector text). Fine for now; a future refinement could OCR only the image pages and splice.

### Async wiring (Laravel)
- `export_jobs` table + `ExportJob` (+ `ExportJobStatus`, `ExportFormat` enums) + `ExportDocumentJob`
  (queued, `tries=1`, `timeout=600`, `failed()` safety net) + `ExportService::process()`
  (queued→processing→completed/failed; stores `exports/<uuid>.docx` on the document's disk).
- Heavy HTTP calls use `services.pdf.export_timeout` (300s) via `PdfServiceClient`'s `longTimeout`.
- Download: `documents.exports.download` (`DocumentFileController::exportDownload`, `can:view`,
  scope-bound to the document, 404 until completed). Viewer: "Export to Word" modal in
  `Show` + `show.blade.php` (`exportToWord()`, `latestExport` computed, `wire:poll` while pending).
- Tests: `tests/Feature/Documents/WordExportTest.php` (dispatch, lifecycle via `dispatchSync` +
  faked `/pdf/export/docx`, failure, download authz/scoping) + 2 client tests in `PdfServiceClientTest`.
  Pest helper `fakePdfExport()` in `tests/Pest.php`.

### Deploy note for Phase 7
- Production must provide the `tessdata` data file (env `PDF_TESSDATA_PREFIX`) — it's gitignored.
  `pdf2docx` pulls numpy/opencv-python-headless; all have cp314 wheels (verified on Python 3.14).
