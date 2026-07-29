# Phase 2 — Page Operations (merge / split / reorder / rotate / delete)

**Status:** ✅ Done
**Depends on:** Phase 1
**Last updated:** 2026-06-16 (built & tested)

> **📎 Context to load — read ONLY these (saves tokens):**
> `STATUS.md` (small; what's done) · **this file** · Phase 1's Handoff notes ·
> `ARCHITECTURE.md` §3, §4, §7 only.
> Skip `README.md` and other phase files unless a Handoff note below points you there.
>
> **Golden Rule (always):** never regenerate the PDF — edits are non-destructive overlays.
> 100% fidelity page ops (PyMuPDF copies pages without re-rendering) — the first quick win.

## Goal

A user can reorganize a document at the page level: reorder, rotate, delete pages, split
into multiple files, and merge multiple documents into one — all producing a new
`document_version`, never mutating the original.

## Scope

**In:**
- Page manager UI: thumbnail grid with drag-to-reorder, select, rotate, delete.
- Split: extract a page range / split every N pages into new document(s).
- Merge: combine several of the user's documents (chosen order) into one new document.
- Each operation writes a **new version** (or a new document for split/merge) via Python.

**Out:** content editing (Phase 3), forms/signatures (Phase 4).

## Tasks

- [x] Python: `POST /pdf/pages` accepting an operation spec. Implemented as 3 ops —
      `organize` (reorder+rotate+delete), `split`, `merge` — via PyMuPDF `insert_pdf` +
      `set_rotation` (lossless copy). pytest each op (14 tests).
- [x] Extend `PdfServiceClient` with `pages(files, spec)` (multiple files for merge).
- [x] Livewire page-manager component (`Documents/Organize`) rendering thumbnails client-side
      with PDF.js; drag-and-drop = **native HTML5 DnD in Alpine** (no new dependency).
- [x] Persist results: new `document_versions` row (organize/restore) or new `documents`
      rows (split/merge). Originals never mutated.
- [x] Versions UI: viewer "Versions" modal — view/download/restore each version + the
      preserved original; "Edited" badge; active version is rendered.
- [x] Routes + nav; "Organize pages" / "Split" / "Versions" actions in the viewer; "Merge"
      multi-select flow in the library.
- [x] Pest feature tests for each op (Http::fake) + version/document creation + ownership (23 tests).
- [x] pytest for each PyMuPDF op with fixtures (page counts, rotation metadata, ranges).
- [x] Tests green; Pint + larastan(L7) + ruff/black + Vite build.

## Acceptance criteria

- Reorder/rotate/delete produce a correct new version; original page count/content of the
  original file unchanged on disk.
- Split yields the expected number of documents with correct page ranges.
- Merge yields one document with pages in the chosen order.
- All scoped to the owner; tested.

## Tests required

- pytest: page count + rotation + range correctness for each operation.
- Pest: each operation flow, version/document creation, authorization.

## Handoff notes — DONE 2026-06-16

**`/pdf/pages` spec schema** (Phase 3 adds `bake` as a sibling route; mirror this style)

Multipart request: one-or-more repeated `files` parts + a `spec` **form field** holding JSON
discriminated on `op`. Response: `{ "outputs": [{ "page_count": int, "content_base64": str }] }`
(one output for organize/merge, many for split). Pages are copied with PyMuPDF `insert_pdf`
(+`set_rotation`) — never re-rendered (Golden Rule). Bad input ⇒ HTTP 422.

- `{"op":"organize","pages":[{"source":<1-based>,"rotate":<deg>}, …]}` — the desired **final**
  page list, in order. **Delete** = omit a page; **reorder** = list order; **rotate** = a
  multiple-of-90 delta **added to the page's current rotation**. ≥1 page required; out-of-range
  `source` ⇒ 422. Needs exactly 1 file.
- `{"op":"split","ranges":[[s,e],…]}` **or** `{"op":"split","every":N}` (provide exactly one).
  Ranges are inclusive 1-based. → one output per range / per N-page chunk. Needs exactly 1 file.
- `{"op":"merge"}` — concatenates the uploaded `files` **in order**. Needs ≥2 files.

Files: `pdf-service/app/schemas/pages.py` (pydantic union), `app/services/pdf_pages.py`
(`parse_pages_spec` + `apply_page_operation`), route in `app/routers/pdf.py`. Tests:
`tests/test_pdf_pages.py`. **Decision:** reorder/rotate/delete are unified under `organize`
(the plan's 5-op enum was illustrative) — one primitive = one page-manager "Save", fewer
round-trips. Laravel client: `PdfServiceClient::pages(array $files, array $spec): array`
(`$files` = `[['contents'=>bytes,'filename'=>name], …]`).

**Versioning model** (Phase 3 bake will create versions the same way)

- `document_versions` gained `page_count` + `size_bytes`
  (`2026_06_15_054600_add_page_metadata…`). Each row = a derived PDF on the `pdfs` disk under
  `documents/versions/<uuid>.pdf`; `version_number = max+1` per document; `label` describes the op.
- `App\Services\PageOperationService` is the single orchestrator:
  `organize(doc, pages, user)` & `restore(doc, version, user)` → new `DocumentVersion`;
  `split(doc, spec, user)` → `Collection<Document>`; `merge(Collection<Document>, user)` →
  `Document`. It reads each document's **active bytes**, calls `pages()`, and stores results.
  Split/merge children get a cover thumbnail (best-effort) and `meta.split_from` /
  `meta.merged_from`. Merge source_type = shared type or `mixed`.
- **Active version** = highest `version_number`, else the original. `Document::latestVersion()`
  (HasOne `ofMany`), `Document::activePath()` / `activePageCount()`. **larastan note:** the
  latter two query the column via `->value(...)` + `is_string/is_int` narrowing — larastan
  types every relation/builder single-model fetch as **non-null**, so `?->` on it is rejected
  (`nullsafe.neverNull`); `value()` returns `mixed` and sidesteps that cleanly. In Blade,
  `$document->latestVersion?->…` is fine (external access honours the `@property-read` and the
  eager-loaded relation — `Index` eager-loads `latestVersion` to avoid N+1).
- **Restore = append-only**: copies the chosen version's bytes into a brand-new latest version
  (no `current_version` pointer column; history is never rewritten).
- Streaming: `documents.versions.file` / `.download` (scoped bindings tie `{version}` to
  `{document}` ⇒ 404 if mismatched; `can:view|download,document` for ownership) in
  `DocumentFileController@versionShow/versionDownload`. `documents.file`/`download` still serve
  the **immutable original**. The viewer/page-manager load the active version's URL when present.

**UI**

- `Documents/Organize` (route `documents.organize`, `can:update`) + `organize.blade.php` host the
  Alpine `pageManager` (`resources/js/pdf-editor/page-manager.js`, registered in `app.js`): PDF.js
  thumbnails in a grid, **native HTML5 drag-to-reorder**, per-tile rotate (CSS-preview)/delete,
  Reset, and "Save as new version" → `$wire.save(payload)`; `Organize::save()` sanitizes
  (bounds + 90° multiples) and calls the service.
- Viewer (`Documents/Show`) gained: active-version URL, an "Organize pages" link, a **Split**
  modal (every-N or ranges), and a **Versions** modal (view/download/restore + original).
- Library (`Documents/Index`) gained per-card checkboxes → a selection bar → a **Merge** modal
  with up/down reordering. Selection is scoped to the owner in `selectedDocuments()` (a tampered
  id for another user's doc is silently dropped ⇒ can't merge foreign docs).

**Gotchas**
- Calling a Livewire action with an array arg + container-injected service:
  `->call('save', $pages)` works because `save(array $pages, PageOperationService $svc)` resolves
  `$svc` from the container and maps the passed arg to `$pages`.
- `Http::assertSent` closures that read the `spec` part must guard on the URL **first** — split/
  merge also fire `/pdf/thumbnails` (cover generation), whose parts have no `spec`.
- `Route::livewire()` full-page routes (index/show/organize) don't appear in `route:list` in this
  setup but resolve at runtime (same as Phase 1) — verified via feature tests.

**Not done here (future phases)**: overlay editor + `/pdf/bake` (Phase 3), forms/signatures,
export, AI. Page-manager rotation is a CSS preview only — the real rotation is baked server-side.
Trashed-document/version cleanup (orphaned files) still deferred to Phase 7 polish.
