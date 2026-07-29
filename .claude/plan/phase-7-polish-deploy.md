# Phase 7 — Polish, Tests, Deploy & Portfolio Presentation

**Status:** ✅ Done (deploy = files written, not build-verified — Docker not installed locally)
**Depends on:** all prior phases (do the parts that apply to whatever shipped)
**Last updated:** 2026-06-22 (Session: polish + tests + Docker + portfolio README)

> **📎 Context to load — read ONLY these (saves tokens):**
> `STATUS.md` (read fully — this phase acts on whatever actually shipped) · **this file** ·
> `ARCHITECTURE.md` §5, §6, §7, §8 only.
> Skim other phase files' Handoff notes only for features you're polishing.
>
> **Golden Rule (always):** never regenerate the PDF — edits are non-destructive overlays.
> This phase turns a working app into a **portfolio piece**.

## Goal

Make the app feel finished and demoable: consistent UX, solid error/empty/loading states,
good performance, full green test suite, deployment, and presentation material.

## Scope / tasks

**UX & polish**
- [x] Consistent Flux UI across all screens; responsive; dark mode honored. (Landing + dashboard
      rebuilt; editor/viewer/AI panel were already Flux. Dark mode via Flux appearance.)
- [x] Loading / empty / error states everywhere; honest copy. (Already present in the document
      flows — verified via grep; landing + dashboard empty/CTA states added.)
- [x] A short guided "first upload → edit → download" happy path. (Seeded sample library + dashboard
      CTA + landing CTAs; samples designed to demo each feature.)
- [x] Landing/welcome page that explains the product. (`welcome.blade.php` rewritten.)

**Quality**
- [x] Fill test gaps. (`WelcomeTest`, expanded `DashboardTest`, `DemoSeederTest` — Pest 138 pass/1 skip.)
- [x] `pytest` suite green (79). End-to-end Laravel↔Python is covered by the existing HTTP-fake contract
      tests per feature; a Pest **browser** test was left optional (not added).
- [x] Larastan (L7, 0) clean; `vendor/bin/pint` clean; `ruff` + `black` clean (ruff added to the venv).
- [~] Performance pass: Index eager-loads `latestVersion`; dashboard uses query aggregates (no N+1);
      thumbnails are client-side (PDF.js) + a cached cover. **Light pass only** — no load testing / queue
      tuning / large-file profiling. (See handoff.)

**Deploy**
- [x] Containerize with Docker Compose (app + pdf-service + Postgres + queue worker). **Files written,
      not build-verified** (Docker not installed locally).
- [x] Hosting documented (VPS+compose vs managed split) in the README — not committed to a specific host.
- [x] Production config documented: secrets, HTTPS, `config/route/view:cache` (entrypoint when
      `APP_ENV=production`), persistent volumes, service-to-service shared secret. (S3 disk left as a
      future option; `local`/`pdfs` disk used.)
- [x] Build pipeline (`npm run build`, baked into the image) + deploy steps documented (README runbook).

**Portfolio presentation**
- [x] Project README (root) with architecture diagram (mermaid), the fidelity story, and the smart
      Word-export story. Screenshots are **referenced + a capture checklist** (`docs/screenshots/`); the
      images themselves are for the user to capture (no running app/Docker here).
- [x] Seed/demo data + sample PDFs (`DemoDocumentsSeeder` + `database/seeders/samples/`).
- [x] Write-up of engineering decisions (README "Engineering decisions" + the plan's decisions log).

## 🐳 Setup guide — Docker & deployment (beginner, step-by-step)

> You asked to be hand-held through Docker. Read this when you reach Phase 7 — it assumes the
> app already runs locally (Laravel + `pdf-service` + PostgreSQL). **Claude writes all the
> Docker files for you; you mainly install Docker Desktop and set secrets.**

### A. Why Docker here (concept)

This app has several parts that must run together: the Laravel web app (PHP), the Python
`pdf-service`, **PostgreSQL**, and a **queue worker**. Docker packs each into a **container**
so the whole stack starts with one command and runs identically on your machine and the
server — great for deploy and a strong portfolio signal (reproducible infra).

Mental model: **image** = frozen recipe · **container** = a running instance of it ·
**`docker-compose.yml`** = the file that declares all services and how they connect.

### B. Install Docker Desktop (Windows) — YOUR manual steps

1. Open **PowerShell as Administrator** and enable WSL2, then reboot when asked:
   ```powershell
   wsl --install
   ```
2. Install **Docker Desktop** from https://www.docker.com/products/docker-desktop/ (keep the
   **WSL2 backend** option).
3. Launch Docker Desktop; wait until it shows **"Engine running"**.
4. Verify in a normal terminal:
   ```powershell
   docker --version
   docker run hello-world
   ```
   A success message from `hello-world` = Docker works. ✅

> This is the only heavy manual step (admin + reboot). Everything below, Claude writes for you.

### C. What we'll containerize (the compose plan)

| Service | Image / build | Role |
|---|---|---|
| `db` | `pgvector/pgvector:pg18` | PostgreSQL **with pgvector** preinstalled (Phase 6 RAG) |
| `app` | build (PHP + nginx) | Laravel web app |
| `pdf-service` | build (`pdf-service/Dockerfile`) | Python FastAPI |
| `queue` | same image as `app` | runs `php artisan queue:work` |
| `assets` | node | builds frontend (`npm run build`) |

Volumes persist Postgres data + uploaded PDFs. A shared network lets `app` reach the Python
service by name (`http://pdf-service:8001`) and the DB by `db:5432`.

### D. Two ways to start (pick one)

- **Route 1 — Laravel Sail (easiest).** Sail already ships with the project. Run
  `php artisan sail:install` (choose `pgsql`) to generate a `docker-compose.yml` for
  Laravel + Postgres; Claude then extends it with `pdf-service` + the queue worker.
- **Route 2 — hand-written compose.** Full control; Claude writes the whole
  `docker-compose.yml` + Dockerfiles. Use if Sail's defaults get in the way.

Recommended: start with Sail, then extend.

### E. The pdf-service Dockerfile (Claude writes this) — what to expect

```dockerfile
FROM python:3.12-slim            # 3.12 for max wheel compatibility (see Phase 0 §F)
WORKDIR /app
# OCR/Word system deps (tesseract, …) get added in Phase 5/6
COPY requirements.txt .
RUN pip install --no-cache-dir -r requirements.txt
COPY app ./app
CMD ["uvicorn", "app.main:app", "--host", "0.0.0.0", "--port", "8001"]
```

### F. Run it locally with Compose

```powershell
docker compose build                            # build images
docker compose up -d                            # start everything in the background
docker compose exec app php artisan migrate     # create tables in the container's Postgres
docker compose logs -f pdf-service              # watch a service's logs
docker compose down                             # stop everything
```

Open the app at its mapped port (e.g. `http://localhost:8080`). Inside the network the Python
service is `http://pdf-service:8001` — set `PDF_SERVICE_URL` to that for the `app` service.

### G. Going to production (decide here)

- **Simplest real host:** a small VPS with Docker → `git pull` → `docker compose up -d`.
- **Managed split:** Laravel on **Laravel Cloud**, `pdf-service` on a container host
  (Fly.io / Render), **managed Postgres** (with pgvector). Fewer ops, more moving parts.

Harden: real `PDF_SERVICE_SECRET`, strong DB password, HTTPS, `APP_ENV=production`,
`php artisan config:cache`, persistent volumes for uploads.

### H. What's manual from YOU vs automated

- **You:** install Docker Desktop + WSL2 (admin/reboot); pick the host; set production secrets.
- **Claude:** writes `docker-compose.yml`, the `app` + `pdf-service` Dockerfiles, env wiring,
  and the exact build/migrate commands; verifies the stack comes up green.

## Acceptance criteria

- A stranger can sign up, upload, edit, and download without confusion.
- Whole test suite green; static analysis clean.
- App deployed and reachable; both services healthy in production.
- README/presentation clearly communicates the value and the engineering.

## Handoff notes

**Session 2026-06-22 — Phase 7 shipped (the app is feature-complete + portfolio-ready).**

### What's done
- **Polish/UX:** new portfolio **landing** (`resources/views/welcome.blade.php`) and a useful signed-in
  **dashboard** (`app/Http/Controllers/DashboardController.php` + `resources/views/dashboard.blade.php`:
  stat cards + recent docs + empty state). Branding switched to **"PDF Studio"** via `config('app.name')`
  (default in `.env.example`); `app-logo.blade.php` + `layouts/app/sidebar.blade.php` de-starter-kitted
  (removed the Laravel-starter "Repository"/"Documentation" links). `dashboard` route is now a controller
  (route-cacheable).
- **Demo data:** `database/seeders/DemoDocumentsSeeder.php` (idempotent) + `DatabaseSeeder` creates
  **`demo@example.com` / `password`** and a 3-doc library from committed samples in
  `database/seeders/samples/` (Welcome / Quarterly Report (3pp) / Invoice). Regenerate with
  `pdf-service/.venv/Scripts/python.exe database/seeders/samples/generate_samples.py`.
- **Docker:** root `Dockerfile` (multi-stage; PHP-FPM + nginx + supervisor, self-contained, port 8080),
  `pdf-service/Dockerfile` (python:3.12-slim), `docker-compose.yml` (db `pgvector/pgvector:pg18` + app +
  queue + pdf-service), `docker/{nginx.conf,php.ini,supervisord.conf,entrypoint.sh}`, both `.dockerignore`s,
  and Docker/AI env vars in `.env.example`.
- **Portfolio:** root **`README.md`** (mermaid architecture diagram, fidelity + smart-export + RAG stories,
  local + Docker runbooks, deployment, testing, engineering decisions) + `docs/screenshots/README.md`
  capture checklist.
- **Gates:** Pest **138 pass / 1 skip**, pytest **79**, larastan(L7) **0**, Pint clean, ruff + black clean,
  `npm run build` OK. (`ruff` was missing from the venv — installed; it's already in `requirements.txt`.)

### Pending / for the user (cannot be done from this dev box)
1. **Verify the Docker stack** — Docker Desktop is NOT installed here, so the compose files are written but
   never built. Run `docker compose build && docker compose up -d && docker compose exec app php artisan
   migrate --seed`. Watch for: opencv shared libs in `pdf-service` (libgl1/libglib2.0-0 are installed in
   the Dockerfile), the Vite build needing vendor (handled by the `vendor` stage), and `APP_KEY` being set
   in `.env` before `up` (entrypoint runs `config:cache` when `APP_ENV=production`).
2. **Capture screenshots** — seed an instance, log in as the demo user, and capture the shots listed in
   `docs/screenshots/README.md` (filenames are referenced by the README).
3. **Pick + point a host**, set production secrets (`APP_KEY`, real `PDF_SERVICE_SECRET`, strong
   `DB_PASSWORD`, optional `ANTHROPIC_API_KEY` for live AI), and put TLS in front of the `app` service.
4. **OCR language data** — download `eng.traineddata` into `pdf-service/tessdata/` (gitignored) for OCR /
   scanned Word export / scanned-doc AI. Without it those gracefully degrade.

### Known limitations to mention in the writeup
- PDF→Word is best-effort (text + rough layout), not pixel-perfect — the UI says so.
- RAG ranking is in-process cosine over JSON vectors (pgvector deferred); fine for owner-scoped libraries.
- Default embeddings are the deterministic `hash` provider (lexical) unless `AI_EMBEDDING_PROVIDER=voyage`.
- Performance pass was light (no load testing / queue tuning / large-file profiling).
- True in-place text editing remains a stretch goal (overlay/whiteout is the supported path).

### Gotchas for the next session
- **PHP/Composer/Artisan run in PowerShell only** (Herd); the Bash tool has no `php`. larastan is the
  authority on types — the IDE's intelephense throws false "Undefined type Route/Seeder" P1009 noise.
- **Python tools** live in `pdf-service/.venv`; run black/ruff with `--config pdf-service/pyproject.toml`
  when linting files outside `pdf-service/` (e.g. the sample generator).
- The demo user changed from `test@example.com` → **`demo@example.com`**.
