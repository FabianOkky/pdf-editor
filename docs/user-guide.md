# User guide

A walkthrough of everything Lapis does, in the order you would meet it. If you seeded the demo
library ([Getting started](getting-started.md)), sign in as `fabian@example.com` / `password` —
the three sample documents are chosen so every feature below has something to work on.

**Throughout: your original file is never modified.** Every operation writes a new version or a
new document. You can always get the untouched upload back.

---

## The library

`/documents`

A private grid of your documents, newest first, each with a cover thumbnail, page count and
size. Nobody else can see or reach them — every query is scoped to the signed-in user and every
file route is gated by a policy.

### Upload

Drag a PDF onto the drop zone or use the file picker. Before anything is stored, Lapis checks:

- the file really is a PDF,
- it is within `PDF_MAX_UPLOAD_MB` (default 25 MB),
- it has at most `PDF_MAX_PAGES` pages (default 500),
- it is not password-protected.

Each check has its own message. A password-protected file tells you to remove the password
rather than failing vaguely.

On success the document is analysed once: page count, per-page dimensions, a cover thumbnail,
and whether it is **native** (real text), **scanned** (images only) or **mixed**. That
classification is what later lets the Word export pick the right strategy.

### Rename, download, delete

- **Rename** — inline; the title is a label, the stored file is untouched.
- **Download** — hands you a PDF **with your pending edits applied**. Any unbaked overlays are
  baked into a new version first, so what you get matches what you see.
- **Download original** — the pristine upload, byte for byte, no matter how much you have
  edited.
- **Delete** — a soft delete. The document moves to Trash.

### Trash and restore

Deleted documents stay recoverable. **Restore** brings a document back with its versions,
overlays and history intact.

### Merge

Select two or more documents, order them with the up/down controls, and merge. The result is a
**new document** — the inputs are left exactly as they were. Each input's pending edits are
baked first, so a merge captures your work rather than merging pristine originals. Selecting
the same document twice is rejected.

---

## The viewer

`/documents/{id}`

Rendered client-side by PDF.js — the same engine Firefox ships. The original bytes are streamed
to the browser as-is, so what you see is exactly what the file contains. There is a page rail
for navigation, plus zoom and page controls.

The viewer always shows the **active bytes**: the newest version if one exists, otherwise the
original upload.

From here you can reach the page manager, the editor, the AI assistant, Word export, split, and
the version history.

### Version history

Every bake, page operation and restore appends a version, labelled with where it came from
("Reordered pages", "Applied edits", and so on). You can view or download any version.

**Restore** is append-only: restoring version 2 creates version 5 with version 2's contents. No
version is ever destroyed, so you cannot lose work by exploring.

---

## The page manager

`/documents/{id}/organize`

Thumbnails of every page, with:

- **Reorder** — drag and drop.
- **Rotate** — 90° at a time, per page.
- **Delete** — remove pages.

Nothing is applied until you save; then all three are sent as one operation and a new version is
created. Pages are **copied**, never re-rendered, so fidelity is exact — text stays text, vector
graphics stay vectors, embedded fonts stay embedded.

### Split

From the viewer, split a document either by explicit page ranges or into fixed-size chunks. Each
piece becomes a **new document** in the library; the source document is untouched.

---

## The overlay editor

`/documents/{id}/edit`

This is where the Golden Rule is most visible. Every tool below adds a **layer on top** of the
page. Nothing underneath is rewritten.

| Tool | What it does |
|---|---|
| **Text** | Place a text box anywhere. Font size, weight and colour are adjustable. |
| **Whiteout** | An opaque rectangle that hides what is under it. |
| **Highlight** | A translucent colour wash. |
| **Underline** / **Strike** | A rule along or through a region. |
| **Shapes** | Rectangle, ellipse, line. |
| **Freehand** | Draw with the mouse or a stylus. |
| **Image** | Place a picture and scale it. |
| **Signature** | Drop in a saved signature. |

### Editing existing text

There is no in-place text mutation — that is what corrupts documents. Instead: **whiteout the
old text, then place a new text box on top**. The editor helps by matching the original font
size and family where it can detect them, so the replacement blends in.

### Autosave, undo, redo

Edits are saved as you work, so a refresh or a lost connection does not cost you anything. Undo
and redo are held client-side for the current session.

### Placement is zoom-independent

Geometry is stored in PDF points from the bottom-left of the page, not in screen pixels. An edit
made at 400% zoom lands in exactly the same place as one made at 50%. Rotated pages are handled
too: baking temporarily un-rotates the page so overlays are never drawn at 90° to the content.

### Applying edits

Your edits live as data until they are **baked**. Baking draws them onto a copy of the active
bytes and saves the result as a new version, then clears the overlay layer.

You rarely need to do this explicitly, because **every path that hands you a file bakes first**
— download, Word export, page operations, split and merge. Edit, then download, and you get
your edits. The viewer shows how many edits are pending.

---

## Forms and signatures

### Filling forms

If a PDF carries an interactive AcroForm, Lapis detects its fields — text boxes, checkboxes,
radios, dropdowns — and positions inputs over them. Read-only fields are respected.

Filled values are written as `form_field` overlays and baked like any other edit, which keeps
the whole flow additive.

### Signatures

Create a signature three ways:

- **Draw** it with a mouse, trackpad or stylus.
- **Type** it and have it rendered in a signature face.
- **Upload** an image of a real signature.

Signatures are **saved to your account**, so you draw yours once and reuse it. Place one on any
page, scale and position it, and it bakes onto the page like any other overlay.

---

## OCR

Scanned PDFs are images with no text layer: you cannot select, search or copy from them. OCR
fixes that, producing a **searchable PDF** — the original page image with an invisible text
layer behind it, so the document still looks identical.

Lapis uses the Tesseract engine **bundled inside PyMuPDF**, so there is no system Tesseract or
Ghostscript to install — only a small language data file. See
[Getting started § OCR](getting-started.md#6-enable-ocr-optional-but-recommended).

Results are cached by content hash, so OCR'ing the same document twice is instant.

---

## Export to Word

From the viewer: **Export to Word**. Any pending edits are baked first, then the export runs
**asynchronously** — the modal polls and reveals the download when it is ready. (The queue
worker must be running: `php artisan queue:listen`.)

PDF → Word is inherently lossy, so the goal is best-effort and honest about it. The trick is
that one converter does not fit both kinds of PDF:

| Your document | What Lapis does |
|---|---|
| **Native** (real text) | `pdf2docx` reconstructs a layout-aware `.docx`. Runs it silently drops — text overlapping a watermark or logo — are recovered into a labelled appendix, comparing words with ligatures folded and accents stripped so a PDF's "oﬃce" is not mistaken for missing text. |
| **Scanned** (images) | `pdf2docx` *refuses* image-only input, so a naive tool hands you an empty file. Lapis runs **OCR first** and rebuilds the document from the recognized spans — carrying over real page size and orientation, margins inferred from where the text sits, per-span font size and weight, detected paragraph alignment, headings, and a column-aware reading order. |
| **Mixed** | Both, page by page. `pdf2docx` keeps the native pages' layout, and OCR runs on **only** the image-only pages, whose text is folded back in. |

If `pdf2docx` fails outright, the export falls back to the span-rebuilding renderer rather than
returning nothing. The engine that actually ran is recorded on the job.

---

## The AI assistant

A side panel in the viewer, with three modes. It works on **native and scanned** documents —
scanned ones are OCR'd first, so you can ask questions about a photographed contract.

### Chat with your PDF

Ask a question and get an answer **grounded in the document**, with `(p. N)` citations so you
can verify it. Under the hood the document is chunked per page, embedded, and the most relevant
chunks are retrieved and given to the model as context. Conversation history is kept per
document.

### Summarize

Summarize the whole document or a single page.

### Translate

Translate the document or a page into another language. Targets come from a fixed list.

### Choosing a model

A toggle in the panel switches between a **local Ollama** model (offline, no API key — the
default) and **Google Gemini**, per request, with no restart. Anthropic Claude is supported as a
third backend. The model that answered is shown in the panel footer.

### What it will not do

- **AI output never touches your PDF.** Answers, summaries and translations stay in the panel.
  If you want text on the page, you place it yourself with the editor.
- Requests are rate-limited per user, and very long documents are truncated to a character cap
  before being sent.
- A failed call rolls back your question too, so the history never shows a question with no
  answer.

Chat and summaries answer in the language of the document; translation goes to the target you
picked.

---

## Account and settings

Authentication is handled by Laravel Fortify: registration, login, and password reset. Settings
cover your profile, your password, appearance (light / dark / system) and account deletion.
Reaching the security settings requires confirming your password again, so a walk-up to an
unlocked browser cannot change your credentials.

Fortify also ships two-factor authentication and email verification; neither is enabled in this
build. Turning them on is a matter of adding `Features::twoFactorAuthentication()` and
`Features::emailVerification()` to `config/fortify.php` (and, for verification, having `User`
implement `MustVerifyEmail` — the import is already there, commented out).

Deleting your account cascades to your documents, versions, overlays, signatures and AI history.

---

## Keyboard and accessibility notes

Overlays are real DOM elements rather than canvas drawings, so the page text underneath stays
selectable and screen-reader accessible, and the editor's controls are reachable by keyboard.

---

## See also

- [Troubleshooting](troubleshooting.md) — when something does not behave as described here
- [Architecture](architecture.md) — why it works this way
