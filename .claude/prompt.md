# Kickoff Prompts — paste one of these into a NEW chat session per phase

This file exists so you can start a fresh session for any phase and Claude immediately has
full context. Copy the **Universal preamble**, then append the **phase block** you want to
work on. (Talk to Claude in Indonesian if you like — the project files and code stay English.)

---

## Universal preamble (always paste this first)

```
You are continuing work on the "PDF Editor" project (Laravel 13 + Livewire 4 + Flux UI
front, Python FastAPI microservice for PDF/AI processing).

Before doing anything, load context CHEAPLY — do NOT read every plan file:
1. Open the specific .claude/plan/phase-N-*.md below FIRST and read its
   "📎 Context to load" block — it lists exactly which files/sections you need.
2. Read .claude/plan/STATUS.md (it's small) to see what's already done.
3. Read ONLY the ARCHITECTURE.md sections the phase file names — not the whole doc.
4. Read earlier-phase "Handoff notes" only if the phase file points you to them.
You do NOT need to read README.md every session (it's one-time orientation).

Rules:
- Follow the Golden Rule: never regenerate the PDF; edits are non-destructive overlays.
- Follow root CLAUDE.md conventions (Laravel Boost guidelines, Pint, Pest).
- Every change must be tested (Pest for Laravel, pytest for Python) and tests must pass.
- Communicate with me in Indonesian; keep all code/comments/docs in English.

When you FINISH (or pause), you MUST:
1. Update .claude/plan/STATUS.md (phase row + shared-infra checkboxes + decisions log).
2. Fill the "Handoff notes" section of the phase file with what's done, what's pending,
   and anything the next phase needs to know.
```

---

## Phase 0 — Foundation & scaffolding

```
Work on PHASE 0. Target file: .claude/plan/phase-0-foundation.md
Goal: git init + Python FastAPI skeleton (GET /health, shared-secret auth) + PdfServiceClient
in Laravel + documents/document_versions models & migrations + storage/queue config, all tested.
Do the tasks in order, run php artisan test --compact and pytest, then update STATUS + handoff.
```

## Phase 1 — Document management

```
Work on PHASE 1. Target file: .claude/plan/phase-1-document-management.md
Goal: upload PDFs (validated) → store immutably → call Python /pdf/info → library/dashboard
→ PDF.js viewer with navigation/zoom/thumbnails. Owner-scoped via DocumentPolicy. Fully tested.
Then update STATUS + handoff.
```

## Phase 2 — Page operations

```
Work on PHASE 2. Target file: .claude/plan/phase-2-page-operations.md
Goal: merge / split / reorder / rotate / delete pages via Python /pdf/pages (PyMuPDF, 100%
fidelity), producing new versions/documents without mutating originals. Page-manager UI with
drag-and-drop. Fully tested. Then update STATUS + handoff.
```

## Phase 3 — Overlay editor

```
Work on PHASE 3. Target file: .claude/plan/phase-3-overlay-editor.md
Goal: non-destructive overlay editor (text, whiteout, highlight/underline/strike, shapes,
freehand, image) stored as document_overlays JSON in PDF user-space; bake to flattened PDF via
Python /pdf/bake → new version. Original stays pixel-identical outside edits. True in-place text
edit is a stretch goal only. Fully tested. Then update STATUS + handoff.
```

## Phase 4 — Forms & signatures

```
Work on PHASE 4. Target file: .claude/plan/phase-4-forms-signatures.md
Goal: detect/fill AcroForm fields (Python) + signatures (draw/type/upload, reusable per user) as
overlay types; flatten on download via existing bake. PKI/digital signing is out of scope.
Fully tested. Then update STATUS + handoff.
```

## Phase 5 — Smart Word export

```
Work on PHASE 5. Target file: .claude/plan/phase-5-word-export.md
Goal: PDF→DOCX with smart pipeline — detect native vs scanned; OCR-first for scans (the step
free tools skip); pdf2docx conversion; async queued export job with status + download. Honest
"best-effort" UX copy. The /pdf/ocr capability is shared with Phase 6 — coordinate via STATUS.
Fully tested. Then update STATUS + handoff.
```

## Phase 6 — AI Assistant (Chat + Summarize + Translate) & OCR

```
Work on PHASE 6. Target file: .claude/plan/phase-6-ai-assistant.md
Goal: one AI Assistant panel = Chat with PDF (RAG over document_chunks) + Summarize + Translate,
working for native and scanned (OCR'd) PDFs. AI output stays in the panel — never written back
onto the PDF layout. Resolve provider/model + vector store and log them in STATUS. Keep keys
server-side, add rate limits. Data extraction is a stretch goal. Tests use mocked LLM. Fully
tested. Then update STATUS + handoff.
```

## Phase 7 — Polish, tests, deploy, presentation

```
Work on PHASE 7. Target file: .claude/plan/phase-7-polish-deploy.md
First read STATUS.md to see what actually shipped, then polish UX/states, fill test gaps (green
suite + clean static analysis), deploy both services, and produce the portfolio README with
screenshots, architecture diagram, and the fidelity + smart-export stories. Then finalize STATUS.
```

---

## Tips

- Do **one phase per session** to keep context focused. If a phase is big, run it across
  multiple sessions — the Handoff notes + STATUS board carry the context between them.
- If you change an architectural decision mid-build, update `ARCHITECTURE.md` and the
  STATUS decisions log so future sessions stay consistent.
- You can always ask: "Read .claude/plan/STATUS.md and tell me what's done and what's next."
