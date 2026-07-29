# Phase 3 — Overlay Editor (text, annotate, highlight, shapes)

**Status:** ✅ Done (reviewed & corrected 2026-06-17)
**Depends on:** Phase 1 (viewer), Phase 2 (versions)
**Last updated:** 2026-06-17

> **📎 Context to load — read ONLY these (saves tokens):**
> `STATUS.md` (small; what's done) · **this file** · Phase 1 & 2 Handoff notes ·
> `ARCHITECTURE.md` §1, §3, §4, §7 only.
> Skip `README.md` and other phase files unless a Handoff note below points you there.
>
> **Golden Rule (always):** never regenerate the PDF — edits are non-destructive overlays.
> This is the **heart of the app** — the Golden Rule matters most here.

## Goal

A user can edit a PDF non-destructively by placing an **overlay layer** on top of the
rendered pages: add/replace text (whiteout + new text), highlight/underline/strikethrough,
draw shapes and freehand, and insert images. Edits are saved as structured JSON
(`document_overlays`) and can be **baked** into a flattened, downloadable PDF on demand.

## Scope

**In:**
- Editing canvas over the PDF.js render: select tool, add text box, whiteout box,
  highlight/underline/strike, rectangle/line/ellipse, freehand pen, insert image.
- Each edit stored as a `document_overlays` row with geometry in **PDF user space**
  (points, bottom-left origin) per `ARCHITECTURE.md` §3.
- Properties panel: font family/size/color/weight, fill/stroke, opacity.
- Move/resize/delete/reorder (z-index) overlays; per-page editing.
- Autosave overlays (debounced) via Livewire; undo/redo at the overlay level.
- "Export / Download edited PDF" → Python `POST /pdf/bake` flattens overlays onto a copy
  of the original → new `document_version`.

**Out / stretch:**
- **True in-place text editing** (modifying the original content stream) is a **stretch
  goal**. If attempted: use PyMuPDF redaction to remove the old span, extract & reuse the
  **original embedded font** for the replacement, accept no-reflow. Default UX remains
  whiteout+overlay. Do not block the phase on this.

## Tasks

- [ ] Finalize the `document_overlays` schema + a documented JSON payload shape per type.
      `php artisan make:model DocumentOverlay -mf`.
- [ ] Build the screen↔PDF coordinate transform (zoom/rotation aware) in the JS viewer.
- [ ] Editor toolbar + canvas (Fabric.js or custom Alpine/DOM — pick one, note it) for:
      text, whiteout, highlight/underline/strike, shapes, freehand, image.
- [ ] Properties panel + select/move/resize/delete/z-order/undo-redo.
- [ ] Livewire persistence: create/update/delete overlays (debounced autosave), scoped to owner.
- [ ] Python: `POST /pdf/bake` — input original + overlays JSON → flattened PDF. Implement
      each overlay type with PyMuPDF (`insert_textbox`, `draw_rect` white fill for whiteout,
      annotations/redactions for highlight, `insert_image`). pytest each type for correct
      placement (render-and-compare a region, or assert object presence).
- [ ] Extend `PdfServiceClient` with `bake(documentId)`.
- [ ] "Download edited PDF" action → bake → save version → offer download.
- [ ] Pest feature tests: create/edit/delete overlays, autosave, authorization, bake flow
      (mock Python), version creation.
- [ ] Tests green; Pint + ruff/black.

## Acceptance criteria

- A user can cover wrong text with whiteout and type correct text on top; on download the
  baked PDF shows the change while the rest of the page is **pixel-identical** to the original.
- Highlights/shapes/freehand/images place at the same spot in the baked PDF as on screen
  (within tolerance).
- Overlays persist, reload correctly, and are owner-scoped.
- Original file on disk is never modified.

## Tests required

- pytest: bake correctness per overlay type (placement within tolerance; original page
  content preserved outside the edited region).
- Pest: overlay CRUD, autosave, authorization, bake → version.

## Handoff notes

**State:** Done. Reviewed & corrected on 2026-06-17 (the first pass was functional + tested but
left the gate red). Full gate green: Pint, larastan(L7) **0**, Pest **91 pass / 1 skip**,
pytest **40**, Vite build. Reachable from the viewer ("Edit" button → `documents.editor`).

### What's built
- **DB:** `document_overlays` (`document_id` FK cascade, `page_number`, `type` string-enum,
  `payload` json, `z_index`, `order`, timestamps; index `[document_id, page_number]`).
  Model `DocumentOverlay`, enum `DocumentOverlayType`, factory (+`whiteout()` state).
  `Document::overlays()` HasMany.
- **Editor (client-side):** `App\Livewire\Documents\Editor` + `resources/views/livewire/
  documents/editor.blade.php` + `resources/js/pdf-editor/editor.js` (Alpine `pdfEditor`) +
  `coords.js`. Tools: select, text, whiteout, highlight, underline, strike, rect, ellipse,
  line, freehand, image. Properties panel (color/fill/font size+style/align/thickness/opacity),
  move/resize/delete/bring-to-front, client undo/redo, debounced autosave (800 ms).
- **Persistence:** `Editor::syncOverlays()` validates (known type, page in active-version range,
  payload is an array) and **atomically swaps the whole layer** (delete-all + `createMany` in a
  DB transaction). Owner-scoped via `DocumentPolicy::update`.
- **Bake:** Python `POST /pdf/bake` (`pdf-service/app/services/pdf_bake.py`,
  `schemas/overlays.py`, route in `routers/pdf.py`). `PdfServiceClient::bake()` →
  `PageOperationService::bake()` stores the flattened result as a **new `document_version`**,
  then **clears the overlay layer** (edits are committed into the version, so re-baking can't
  compound them). The original file on disk is never touched.

### Final overlay JSON schema (Phase 4 extends this)
Each overlay row = `{ type, page_number, z_index, order, payload }`. Geometry in `payload` is
**PDF user space — points, origin bottom-left**. Per-type `payload` (validated by pydantic in
`pdf_bake.py`; `x,y` = bottom-left corner, `width,height` ≥ 0):
- `text`: `{x,y,width,height, text, font_size=12, color="#000000", bold=false, italic=false,
  align="left"|"center"|"right"|"justify", opacity=1}` — baked with Base-14 Helvetica
  (`helv/hebo/heit/hebi`). **Non-Latin glyphs won't render** (Base-14 only).
- `whiteout`: `{x,y,width,height, color="#ffffff"}` (opaque rect; border==fill to avoid a seam).
- `highlight`: `{x,y,width,height, color="#ffff00", opacity=0.4}` (translucent filled rect,
  drawn **on top** — tints, doesn't multiply-blend behind text).
- `underline` / `strike`: `{x,y,width,height, color, stroke_width=1.5, opacity=1}` (a hline at
  the bottom / middle of the rect).
- `shape`: `{kind:"rect"|"ellipse"|"line", x,y,width,height, stroke, stroke_width=1.5,
  fill=null, opacity=1}` (`line` uses x,y + **signed** width/height as the two endpoints).
- `freehand`: `{points:[[x,y],…], stroke, stroke_width=2, opacity=1}`.
- `image`: `{x,y,width,height, data:"<base64 or data:URL>", opacity=1}` (PNG/JPEG; stored inline
  in the payload JSON).
- `signature` / `form_field`: **reserved for Phase 4** — accepted & stored but **baked as a
  no-op** (skipped in `pdf_bake._apply`). Phase 4 should add handlers there.

### Canvas library — none (decision)
Rendered as **plain DOM + SVG via Alpine**, mirroring the Phase 2 page-manager. No Fabric.js /
canvas lib (avoids a dependency; CLAUDE.md). Rect-like overlays = absolutely-positioned `div`s;
lines + freehand = an SVG layer; text = a `contenteditable` div. The editor block is inside
`wire:ignore` so Livewire morphing never touches the PDF.js canvas.

### Coordinate-transform gotchas (read before Phase 4 places anything)
- Use **`coords.js`** (`screenToPdf`/`pdfToScreen`) — thin wrappers over PDF.js
  `viewport.convertToPdfPoint`/`convertToViewportPoint`. They return **unrotated** PDF user-space
  points, which is exactly what bake expects. Don't hand-roll the y-flip in JS.
- **Screen y grows down, PDF y grows up.** A rect's stored `y` is its **bottom** edge; the
  on-screen `top` = `pdfToScreen(x, y+height)`. Resizing keeps the screen-top fixed by adjusting
  both `y` and `height`. The **off-page image bug** fixed this pass came from referencing the PDF
  origin (bottom-left) instead of the screen top-left when placing — place via
  `screenToPdf(viewport, cssX, cssY)`, never `pdfToScreen(0,0)+offset`.
- **Rotation:** bake handles `/Rotate ≠ 0` by `set_rotation(0)` → draw in unrotated space (flip
  against the unrotated height) → restore. Locked by
  `test_whiteout_on_a_rotated_page_lands_in_user_space`. The editor needs no special-casing
  because `convertToPdfPoint` already returns unrotated coords.

### Pending / not done (intentional)
- **True in-place text editing** (content-stream redaction + original-font reuse) — explicit
  **stretch goal**, not attempted. Default UX stays whiteout + text overlay.
- **Text fidelity:** browser text wrapping ≠ PyMuPDF `insert_textbox`; overflowing text is
  truncated at bake (box too small). No `insert_htmlbox`/auto-grow yet.
- **No JS unit tests** for `editor.js` (the repo has no JS test runner; same as Phase 1/2). Logic
  is covered by reasoning + the Python bake tests for the wire payload. Consider adding a Pest v4
  **browser** smoke test for the editor in Phase 7.
- **Hardening not added** (low risk, owner-scoped): no cap on overlay count / image byte size in
  `syncOverlays` (whole base64 image rides every autosave + lives in the DB). Revisit if needed.

### For Phase 4 (forms & signatures)
Add `signature`/`form_field` handlers in `pdf_bake._HANDLERS` + payload models, and editor tools
that produce those `type`s — the schema, persistence, autosave, bake→version pipeline, and
coordinate stack are all already in place and reused as-is.
