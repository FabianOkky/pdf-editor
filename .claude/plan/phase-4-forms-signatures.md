# Phase 4 — Forms & Signatures

**Status:** ✅ Done
**Depends on:** Phase 3 (overlay engine + bake)
**Last updated:** 2026-06-18

> **📎 Context to load — read ONLY these (saves tokens):**
> `STATUS.md` (small; what's done) · **this file** · Phase 3's Handoff notes ·
> `ARCHITECTURE.md` §3, §4, §7 only.
> Skip `README.md` and other phase files unless a Handoff note below points you there.
>
> **Golden Rule (always):** never regenerate the PDF — edits are non-destructive overlays.
> Reuses the overlay engine — signatures and form values are specialized overlay types.

## Goal

A user can fill PDF form fields and add signatures (draw / type / upload image), place
them on any page, and download a flattened, signed PDF. High perceived value for a portfolio.

## Scope

**In:**
- **Form filling:** detect existing AcroForm fields (Python) and let the user fill them;
  fall back to overlay text boxes for flat (non-interactive) "forms".
- **Signatures:** draw on a canvas, type (signature fonts), or upload an image; save
  reusable signatures per user; place + resize on the page as an overlay/image.
- **Stamps / date:** quick stamps (approved, date, initials).
- Flatten everything on download (reuse `/pdf/bake`), producing a new version.

**Out:** cryptographic/digital signatures (PKI) — note as future work, not in scope.

## Tasks

- [x] Python: `POST /pdf/form-fields` — list AcroForm fields (name, type, rect, page).
      Plus `POST /pdf/form-fields/fill` (set widget values, optional `flatten` via
      `Document.bake(widgets=True)`). pytest with an in-memory form fixture.
- [x] New overlay types `signature` / `form_field` now bake (were reserved/no-op in Phase 3).
- [x] Saved signatures: `signatures` table (id, user_id, name, type, data) — owner-scoped.
- [x] Signature pad UI (draw/type/upload) + placement reusing the Phase 3 canvas.
- [x] Form-fill UI: detected fields seed `form_field` overlays rendered as inputs/checkboxes.
- [x] Extend bake to flatten form values + signatures into the output.
- [x] Pest feature tests: detect fields, place/persist + bake form_field & signature overlays,
      saved-signature CRUD + reuse + ownership, authorization, client contract. Mock Python.
- [x] pytest: field detection + fill + flatten correctness + bake placement.
- [x] Tests green; Pint + ruff/black.

## Acceptance criteria

- A user fills a real AcroForm PDF and downloads a correctly filled, flattened file.
- A user adds a drawn/typed/uploaded signature, positions it, and it appears correctly in
  the downloaded PDF.
- Saved signatures can be reused across documents; everything owner-scoped.

## Tests required

- pytest: form-field detection, fill, signature flatten placement.
- Pest: fill flow, signature CRUD + placement, download, authorization.

## Handoff notes

**State:** Done. Full gate green: Pint, larastan(L7) **0**, Pest **103 pass / 1 skip**, pytest
**53**, Vite build. Everything reuses the Phase 3 overlay → autosave → `/pdf/bake` → version
pipeline; no new web routes. Reachable from the editor toolbar (Signature + Detect-form-fields).

### What's built
- **Python — detection/fill** (`pdf-service/app/services/pdf_forms.py`, `schemas/forms.py`,
  routes in `routers/pdf.py`):
  - `POST /pdf/form-fields` → `{is_form, fields:[{name,type,value,page_number,x,y,width,height,
    options,readonly,required}]}`. Field rects are returned in **PDF user space (bottom-left)**
    so the editor positions inputs with the same `coords.js` transform overlays use.
  - `POST /pdf/form-fields/fill` (form fields `values` JSON + `flatten` bool) → `{page_count,
    content_base64}`. Sets widget values (checkbox/radio coerced to bool), **skips read-only**
    fields, and when `flatten` bakes appearances into static content via `Document.bake(
    widgets=True)` (drops the interactive widgets). Works on an in-memory copy — Golden Rule.
- **Python — bake** (`pdf_bake.py`): `signature` reuses the **image** handler; `form_field`
  draws the value as a single line via `insert_text` (baseline-placed, alignment via
  `get_text_length`) or an **"X"** for a checked box. `insert_text` (not `insert_textbox`) is
  used because a field's box is usually exactly font-tall and `insert_textbox` writes *nothing*
  when one line overflows by a hair. Unknown overlay types are still skipped (no longer
  "reserved" — the old `test_reserved_types_are_skipped` is now `test_unknown_types_are_skipped`).
- **Laravel**: `PdfServiceClient::formFields()` + `fillFormFields()`. `signatures` table +
  `Signature` model + `SignatureType` enum (Draw/Type/Upload) + factory; `User::signatures()`.
  `Editor` gained `detectFormFields()`, `saveSignature()`, `deleteSignature()`, and the
  `savedSignatures` computed. `sanitizeOverlays`/persistence already accepted the two enum
  cases, so form_field/signature overlays autosave + bake unchanged.
- **Editor UI** (`resources/js/pdf-editor/editor.js` + `editor.blade.php`): a pure-Alpine
  **signature modal** (Draw on a `<canvas>` pad / Type in a cursive font / Upload PNG-JPEG)
  with reusable saved-signature thumbnails (place / delete); a **Detect-form-fields** button
  that seeds `form_field` overlays rendered as in-place text inputs / checkbox toggles; plus a
  Remove-form-fields button and a transient detection banner.

### How AcroForm fields map to the overlay model (key decision)
Detection is **read-only**; the actual fill is done as **`form_field` overlays** flattened by
the existing `/pdf/bake` (this is the "flatten on download via existing bake" path and stays
Golden-Rule-pure — purely additive, original AcroForm untouched). `form_field` payload:
`{x,y,width,height, field_name, field_type, value (string|bool), options[], font_size, color,
align, opacity}`. The Python interactive **fill** endpoint exists and is fully tested but the
**editor UI does not call it** — it's the alternative "produce a real interactive/flattened
AcroForm" primitive (exposed on the client for future use). Bake ignores the extra
`field_name`/`options` keys (pydantic `extra='ignore'`).

Note: because we draw values *on top* of the page, the baked file still contains the original
(now empty) widgets beneath the drawn text — visually correct (the field's own border frames
the value). For a truly widget-free file, use `/pdf/form-fields/fill?flatten=true`.

### Saved-signature storage choice
Stored **inline in the DB** as a PNG/JPEG **data URL** (`signatures.data` longText), not on a
disk. Signatures are tiny and this keeps placement synchronous: the Livewire methods return the
refreshed list (`saveSignature`/`deleteSignature` → array) and the Alpine editor swaps its local
`signatures` state from the return value (the editor is inside `wire:ignore`, so it can't rely
on a Livewire re-render). All three creation modes rasterize to a PNG **client-side**, so every
signature bakes as an image and **no signature font is needed server-side**. Caps: ≤20 per user,
≤5,000,000 data chars, `regex:data:image/(png|jpeg);base64,` (see `Editor` constants).

### PKI / digital signatures — deferred (as planned)
Cryptographic signing (PAdES/PKI) is **out of scope**. Signatures here are visual images only.

### Pending / not done (intentional)
- Choice fields (combobox/listbox) render as a **plain text input** seeded with the current
  value (options are stored in the payload but not shown as a `<select>`). Fine for the MVP.
- The editor uses overlay-bake for fill; the interactive `fillFormFields` client method is
  untested-in-UI (covered by `PdfServiceClientTest` + pytest only).
- No JS unit tests (repo still has no JS runner — same as Phase 1–3). Logic is covered by the
  Python bake tests for the wire payload + the Livewire feature tests. Consider a Pest v4
  browser smoke test for the signature pad / form-fill in Phase 7.
