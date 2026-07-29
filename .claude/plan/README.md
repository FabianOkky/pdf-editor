# PDF Editor — Master Plan

> Web app where a user can manage and **edit their own PDFs live in the browser**,
> with an optional **AI assistant** and a **smart PDF → Word export**.
> This is a **portfolio project** — quality and polish matter more than raw feature count.

This folder (`.claude/plan/`) is the **single source of truth** for the project plan.
Each work session reads these files, does one phase, then updates them. This is how
separate chat sessions "communicate" with each other.

---

## 1. Product vision

Many people get stuck because PDFs feel un-editable: a typo, a wrong date, a missing
signature, or a scanned document they can't reuse. Existing tools often **wreck the
original layout** (fonts, lines, design) or produce a Word file that looks nothing like
the source.

This app solves that with one core principle (see `ARCHITECTURE.md`):

> **The Golden Rule — never regenerate, always overlay.**
> The original PDF bytes are preserved. Edits are applied as an additive layer.
> The original fonts, vector lines and design are never destroyed.

Primary user = the owner (a single authenticated user managing their library). Auth is
already provided by the Laravel + Fortify starter kit (login, register, 2FA, settings).

---

## 2. Tech stack (locked)

| Layer | Tech | Role |
|---|---|---|
| Web app | **Laravel 13 + Livewire 4 + Flux UI** | UI, auth, DB, file management, job orchestration |
| Viewer/editor | **PDF.js + overlay edit layer** (browser) | Pixel-perfect render + non-destructive editing UI |
| PDF engine | **Python + FastAPI** | PyMuPDF (page ops, redaction, bake overlays), OCR, Word export, AI glue |
| Database | **PostgreSQL 18** | documents, versions, overlays, AI data; **pgvector** for RAG (Phase 6) |
| Storage | Laravel `storage/` (local disk first) | Original files, versions, exports |
| Queue | Laravel queue (database driver first) | Async OCR / export / AI jobs |
| AI | LLM via API (provider TBD in Phase 6) | Chat / Summarize / Translate / extraction |

Full detail and the Laravel ↔ Python contract live in `ARCHITECTURE.md`.

---

## 3. Phases

Build order is deliberate: **high-fidelity easy wins first**, hard editing next,
AI last. Each phase has its own file with tasks, acceptance criteria and a handoff
section.

| # | Phase | File | Depends on |
|---|---|---|---|
| 0 | Foundation & scaffolding | `phase-0-foundation.md` | — |
| 1 | Document management (upload, library, view) | `phase-1-document-management.md` | 0 |
| 2 | Page operations (merge/split/reorder/rotate/delete) | `phase-2-page-operations.md` | 1 |
| 3 | Overlay editor (text, annotate, highlight, shapes) | `phase-3-overlay-editor.md` | 1, 2 |
| 4 | Forms & signatures | `phase-4-forms-signatures.md` | 3 |
| 5 | Smart Word export | `phase-5-word-export.md` | 1 (OCR from 6 optional) |
| 6 | AI Assistant (Chat + Summarize + Translate, + OCR) | `phase-6-ai-assistant.md` | 1 |
| 7 | Polish, tests, deploy, portfolio presentation | `phase-7-polish-deploy.md` | all |

> Phases 5 and 6 share the OCR + text-extraction pipeline. Whichever is built first
> creates it; the other reuses it. Coordinate via `STATUS.md`.

---

## 4. How to work a phase (token-efficient — minimal reading)

Context is **scoped per phase** so a session loads only what it needs (saves tokens & time):

1. Open the target `phase-N-*.md` first and read its **"📎 Context to load"** block — it
   names the exact files/sections to read. Read this file fully.
2. Read `STATUS.md` (small) for the done-state, and only the **ARCHITECTURE.md sections**
   the phase file lists — not the whole architecture doc.
3. Read earlier-phase **Handoff notes** only if the phase points you to them.
4. Do the work. Follow the **Golden Rule** (inlined in each phase) and root `CLAUDE.md`.
5. Write/run tests (Pest for Laravel, pytest for Python).
6. **Before finishing:** update `STATUS.md` and the phase file's `Status` + `Handoff notes`.

> This README is **one-time orientation** — you don't need to re-read it every session.

Ready-to-paste kickoff prompts for each phase live in `../prompt.md`.

---

## 5. Status at a glance

See **`STATUS.md`** — it is the live board. Keep it accurate; it is what the next
session trusts.
