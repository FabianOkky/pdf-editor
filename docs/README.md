# Lapis — Documentation

Lapis is a web application for **editing PDFs without breaking them**. A signed-in user
uploads their own documents, edits them live in the browser, and exports the result — while
the original bytes of every upload stay untouched.

This folder is the project's reference documentation. Start with
[Getting started](getting-started.md) if you just cloned the repository.

---

## Table of contents

| Document | What it covers |
|---|---|
| [Getting started](getting-started.md) | Prerequisites, install, run the stack locally or with Docker, demo account |
| [User guide](user-guide.md) | Every feature, end to end: upload, organize, edit, sign, OCR, export, AI |
| [Architecture](architecture.md) | The Golden Rule, the two-service design, request flows, repository layout |
| [Data model](data-model.md) | Database schema, Eloquent models, relationships, enums |
| [PDF service API](pdf-service-api.md) | The REST contract between Laravel and the Python microservice |
| [Configuration](configuration.md) | Every environment variable for both services, with defaults |
| [Testing](testing.md) | Test suites, quality gates, how to run them, what is covered |
| [Deployment](deployment.md) | Docker Compose, VPS, managed split, production hardening |
| [Troubleshooting](troubleshooting.md) | Common failures and how to fix them |
| [Screenshots](screenshots/README.md) | Capture checklist for the images the README links to |

---

## The one idea behind the whole project

> **Never regenerate the whole PDF. Preserve the original bytes; apply edits as an additive
> overlay layer. Editing is non-destructive by default.**

A PDF stores *positioned glyphs*, not a reflowable document. Re-encoding the whole file is
what makes other editors corrupt fonts, vector lines and layout. Lapis never does it: the
upload is immutable, edits are stored as structured JSON operations, and a flattened copy is
produced on demand as a **new version**.

Everything else in the architecture follows from that constraint. See
[Architecture](architecture.md) for the full reasoning.

---

## System at a glance

| Piece | Technology | Responsibility |
|---|---|---|
| Web app | PHP 8.4 · Laravel 13 · Livewire 4 · Flux UI · Tailwind 4 | Auth, document library, database, file storage, queued jobs, RAG retrieval |
| Viewer / editor | PDF.js v6 · Alpine.js · plain DOM | Pixel-perfect rendering and the overlay editing surface |
| PDF engine | Python 3.12 · FastAPI · PyMuPDF · pdf2docx / python-docx | Page operations, overlay baking, OCR, Word export, embeddings, LLM calls |
| Database | PostgreSQL 18 | Documents, versions, overlays, signatures, export jobs, AI data |

---

## Conventions used in this documentation

- Shell snippets assume the repository root unless a `cd` says otherwise.
- Windows examples use Laravel Herd; macOS and Linux equivalents are given where they differ.
- `pdf-service` always refers to the Python microservice in [`pdf-service/`](../pdf-service/).
- "Active bytes" means the newest version of a document if one exists, otherwise the original
  upload — see [Data model](data-model.md#document_versions).
