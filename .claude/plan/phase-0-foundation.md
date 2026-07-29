# Phase 0 — Foundation & Scaffolding

**Status:** ✅ Done
**Depends on:** —
**Last updated:** 2026-06-13 (built, tested & committed)

> **📎 Context to load — read ONLY these (saves tokens):**
> `STATUS.md` (small; what's done) · **this file** · `ARCHITECTURE.md` §2, §3, §4, §7 only.
> Skip `README.md` and other phase files unless a Handoff note below points you there.
>
> **Golden Rule (always):** never regenerate the PDF — edits are non-destructive overlays.
> Phase 0 builds the two-service skeleton + data foundation; no PDF features yet.

## Goal

A running Laravel app + a running Python FastAPI service that can talk to each other,
plus the core `documents` data model. Prove the wiring with a health check end-to-end.

## Scope

**In:**
- Initialize git; sensible `.gitignore` for both Laravel and Python.
- Create `pdf-service/` FastAPI skeleton with `GET /health` returning `{status, version}`.
- Shared-secret auth middleware on the Python side (`X-Pdf-Secret`).
- Python deps managed with a simple **`requirements.txt` + venv** (decided: beginner-friendly,
  no extra install). Formatter: `ruff` + `black`.
- Add `config/services.php` `pdf` block (`url`, `secret`) + `.env`/`.env.example` keys
  (`PDF_SERVICE_URL`, `PDF_SERVICE_SECRET`).
- Create `App\Services\PdfServiceClient` wrapping the HTTP client, with a `health()` method.
- Migrations + models + factories for `documents` and `document_versions`.
- Configure a storage disk for PDFs and the queue driver (database) — config only.
- A tiny Artisan/Pest test that calls `PdfServiceClient::health()` against a fake.

**Out:** any PDF processing, UI, upload — later phases.

## 🛠 Setup guide — environment (do this FIRST, before the Tasks)

> Verified on this machine on 2026-06-13. During the Phase 0 build session, Claude can run
> steps C and D for you; this guide documents them so you understand the stack and can
> start/stop the servers yourself afterwards.

### A. What you already have ✅ (verified)

| Tool | Version | Notes |
|---|---|---|
| Git | 2.54 | all terminals |
| PHP | 8.4.22 | via Laravel Herd — **PowerShell only** |
| Composer | (Herd) | **PowerShell only** |
| Node / npm | 26.2 / 11.13 | already installed |
| Python / pip | 3.14.5 / 26.1 | enough for Phase 0 |

Nothing else needs installing for Phase 0. (Tesseract OCR comes later, Phase 5/6.)

### B. ⚠️ Which terminal to use — IMPORTANT

- **PHP / Composer / `php artisan` / `herd`** only work in **PowerShell** — they live in
  `C:\Users\okkyf\.config\herd\bin`, which is **not** on Git Bash's PATH. Run all Laravel
  commands in **PowerShell**.
- **Python / pip / git / node / npm** work anywhere.
- Rule of thumb: open **PowerShell**, `cd C:\Projects\pdf-editor`, do everything there.

### C. Confirm the Laravel app runs (baseline)

Run in PowerShell from the project root. Most is already done — this just confirms.

```powershell
composer install            # PHP deps (already installed; safe to re-run)
php artisan key:generate    # only if APP_KEY in .env is empty
php artisan migrate         # create database tables
npm install                 # JS deps (already installed; safe)
npm run dev                 # start Vite — keep this terminal running
```

The site is served by **Laravel Herd** at `https://pdf-editor.test`. If it doesn't open,
link it once (from inside the project folder), then open it:

```powershell
herd link pdf-editor
herd open
```

You should see the starter kit's login/register page. ✅ baseline works.

### D. Set up the Python service (`pdf-service`)

A **virtual environment (venv)** keeps this project's Python packages isolated from the
rest of your system. Good news: I checked — **PyMuPDF + FastAPI install fine on your
Python 3.14**, so no extra Python needed.

In a **second** PowerShell tab:

```powershell
cd C:\Projects\pdf-editor
mkdir pdf-service                      # Phase 0 creates the code inside this folder
cd pdf-service
python -m venv .venv                   # create the virtual environment
.venv\Scripts\Activate.ps1             # activate it — prompt now shows (.venv)
python -m pip install --upgrade pip
pip install fastapi "uvicorn[standard]" pymupdf pytest httpx ruff black
```

- ⚠️ If activation fails with *"running scripts is disabled on this system"*, run this
  **once**, then retry the activate line:
  ```powershell
  Set-ExecutionPolicy -Scope CurrentUser RemoteSigned
  ```

After Claude has created `pdf-service/app/main.py` (in the Tasks below), start the service
**from inside `pdf-service` with the venv activated**:

```powershell
uvicorn app.main:app --reload --port 8001
```

Test it: open `http://127.0.0.1:8001/health` (JSON health response) or
`http://127.0.0.1:8001/docs` (auto API docs). ✅
Stop the server with `Ctrl + C`; leave the venv later with `deactivate`.

### E. Day-to-day: you run two terminals

| Terminal | Command | Serves |
|---|---|---|
| Tab 1 — Laravel | `npm run dev` (Herd serves the site) | `https://pdf-editor.test` |
| Tab 2 — Python | activate venv → `uvicorn app.main:app --reload --port 8001` | `http://127.0.0.1:8001` |

### F. ⚠️ Known risk for LATER phases (not Phase 0)

Python 3.14 is brand-new. Phase 0 libs work, but the OCR/Word libraries in **Phase 5/6**
(`ocrmypdf`, `pikepdf`, `pdf2docx`) may not ship 3.14 wheels yet. If an install fails
there, the fallback is to recreate the venv with **Python 3.12**:

```powershell
winget install Python.Python.3.12      # if not already installed
# then, in pdf-service:
Remove-Item -Recurse -Force .venv
py -3.12 -m venv .venv
.venv\Scripts\Activate.ps1
pip install -r requirements.txt
```

This is logged in `ARCHITECTURE.md` §8 as an open item to confirm when Phase 5 arrives.

## Tasks

- [x] `git init`, first commit of the existing starter kit, add `pdf-service/` to repo.
- [x] Scaffold `pdf-service/` per `ARCHITECTURE.md` §2 (main.py, routers, services, schemas, core).
- [x] Implement `core/config.py` (reads secret/env) + `core/auth.py` (verify `X-Pdf-Secret`).
- [x] Implement `GET /health`; add pytest for it (200 + shape, 401 on bad/missing secret).
- [x] Add `pdf-service/requirements.txt` with deps: `fastapi`, `uvicorn[standard]`,
      `pymupdf`, `pytest`, `httpx`, `ruff`, `black`. (Add OCR/docx/AI deps in their phases,
      not now.) Verified to install on the user's Python 3.14 (PyMuPDF ships an `abi3` wheel).
- [x] `pdf-service/README.md`: how to install + run (`uvicorn app.main:app --port 8001`).
- [x] Laravel: add `services.pdf` config + env keys + `PdfServiceClient` with `health()`.
- [x] `php artisan make:model Document -mf` and `DocumentVersion -mf`; fill schema from
      `ARCHITECTURE.md` §3 (only these two tables now). Add relationships + casts.
- [x] Configure PDF storage disk + database queue (config + migrate jobs table).
- [x] Pest test: `PdfServiceClient` health (HTTP fake) + factory tests for the models.
- [x] Run `php artisan test --compact` and `pytest`; both green.
- [x] `vendor/bin/pint --dirty --format agent`; `ruff`/`black` on Python.

## Acceptance criteria

- `uvicorn` serves the Python service; `GET /health` works with the right secret, 401 without.
- `PdfServiceClient::health()` succeeds against the running service (and is unit-tested with a fake).
- `documents` + `document_versions` migrate cleanly; factories produce valid rows.
- All tests green; code formatted.

## Tests required

- pytest: `/health` happy path + auth rejection.
- Pest: `PdfServiceClient` health via `Http::fake`; model + factory tests.

## Handoff notes — DONE 2026-06-13

**What got built**
- Git initialized; initial commit `Initialize PDF Editor: Phase 0 foundation` on `master`.
- **Python service** `pdf-service/` (FastAPI): `app/main.py`, `core/config.py` (env via
  `python-dotenv`), `core/auth.py` (`X-Pdf-Secret`, `secrets.compare_digest`, fails closed),
  `routers/health.py`, `schemas/health.py`. `GET /health` → `{status, service, version}`,
  requires the secret (401 otherwise). pytest: 3 passing.
- venv at `pdf-service/.venv` (Python 3.14). Deps in `requirements.txt` (fastapi,
  uvicorn[standard], pymupdf, python-dotenv, pytest, httpx, ruff, black). Tool config in
  `pdf-service/pyproject.toml` — note `flake8-bugbear.extend-immutable-calls` to silence
  ruff B008 for FastAPI `Depends`/`Header`.
- **Laravel:** `App\Services\PdfServiceClient` (`health()`, `isHealthy()`), bound as a
  singleton in `AppServiceProvider::register()` from `config('services.pdf')`. Config block
  `services.pdf` (url/secret/timeout). Env keys `PDF_SERVICE_URL`, `PDF_SERVICE_SECRET`,
  `PDF_SERVICE_TIMEOUT` in `.env` + `.env.example`.
- **Data model:** `documents` + `document_versions` migrations; `Document` /
  `DocumentVersion` models (relations + enum casts + soft deletes on `Document`); factories
  (`scanned()`, `processing()` states); enums `DocumentSourceType`, `DocumentStatus`.
  Migrated to **PostgreSQL 18** (dev `pdf_editor`, tests `pdf_editor_test`).
- **Storage:** `pdfs` disk (local, private, `storage/app/pdfs`); documents default to it.
- **Queue:** already `database` (env + jobs table from the starter kit) — nothing to add.
- **Tests:** Pest 31 passing / 1 pre-existing skip; pytest 3 passing. Pint clean; ruff+black clean.

**How to run locally**
- Laravel: `npm run dev` (Herd serves `https://pdf-editor.test`).
- Python: from `pdf-service/`, activate `.venv`, `uvicorn app.main:app --reload --port 8001`.
  `/health` needs the `X-Pdf-Secret` header (browser → 401 is expected); `/docs` for Swagger.
- The shared secret lives in root `.env` **and** `pdf-service/.env` — they MUST match. Both
  are gitignored; the `.env.example` files document the keys.

**Deviations / decisions** (also in STATUS decisions log)
- Dep manager = `requirements.txt` + venv (not uv); Python 3.14 (PyMuPDF abi3 wheel works).
- `document_versions` migration timestamp bumped +1s so its FK to `documents` resolves.
- `/health` is secret-protected (matches the planned 401 test).

**For Phase 1**
- Extend `PdfServiceClient` with `info()` / `thumbnails()`; add FastAPI `routers/pdf.py`
  (`/pdf/info`, `/pdf/thumbnails`) + a `services/` module. Commit fixture PDFs under
  `pdf-service/tests/fixtures/`.
- Store uploads on the `pdfs` disk under hashed paths; set `Document.path/disk/size_bytes/mime`;
  fill `page_count` + `source_type` from `/pdf/info`.
- No `DocumentPolicy` yet — create it in Phase 1 to scope by `user_id`.
- IDE may show false "Undefined type/function" diagnostics for Laravel facades/helpers
  (vendor not indexed). Ignore — `php artisan test` is the source of truth.
