# Getting started

Lapis is two services that talk to each other over localhost:

1. the **Laravel web app** (UI, auth, database, file storage, queue), and
2. the **Python `pdf-service`** (everything that touches PDF bytes or runs ML).

You need both running to use the whole feature set. Uploading, browsing and viewing work with
Laravel alone; page operations, editing, OCR, Word export and the AI assistant need the Python
service too.

There are two ways to get there: [run it locally](#option-a--run-it-locally) (best for
development) or [run the whole stack with Docker](#option-b--run-the-whole-stack-with-docker)
(best for trying it out).

---

## Prerequisites

| Requirement | Version | Notes |
|---|---|---|
| PHP | 8.4+ | With `ext-fileinfo`, `ext-zip`, `ext-pdo_pgsql`. [Laravel Herd](https://herd.laravel.com) ships all of them. |
| Composer | 2.x | |
| Node.js | 20+ | With npm, for Vite and the PDF.js bundle. |
| Python | 3.12+ | 3.12 has the widest wheel coverage for PyMuPDF and pdf2docx. |
| PostgreSQL | 16+ (18 recommended) | Two databases: `pdf_editor` and `pdf_editor_test`. |

Docker users need only Docker Desktop (or Docker Engine + Compose v2).

---

## Option A — run it locally

### 1. Clone and install the web app

```bash
git clone https://github.com/FabianOkky/pdf-editor.git
cd pdf-editor

composer install
npm install
```

### 2. Configure the environment

```bash
cp .env.example .env
php artisan key:generate
```

Open `.env` and set at least:

```dotenv
DB_CONNECTION=pgsql
DB_DATABASE=pdf_editor
DB_USERNAME=postgres
DB_PASSWORD=your-postgres-password

# Must be identical to the value in pdf-service/.env
PDF_SERVICE_SECRET=a-long-random-string
```

Generate a secret with `php artisan key:generate --show` (drop the `base64:` prefix) or any
password manager. Every setting is documented in [Configuration](configuration.md).

### 3. Create the databases and seed a demo library

```bash
createdb pdf_editor
createdb pdf_editor_test        # only needed if you intend to run the test suite

php artisan migrate --seed
```

The seeder is idempotent — it creates the demo account only if it does not exist, and skips a
user who already has documents, so you can re-run it safely.

**Demo account**

| Field | Value |
|---|---|
| Name | Fabian Okky |
| Email | `fabian@example.com` |
| Password | `password` |

It comes with a three-document starter library (a native text PDF, a multi-page report, and an
invoice) so every feature has something to work on immediately. The samples live in
[`database/seeders/samples/`](../database/seeders/samples/) and are committed to the repository.

> The demo password is for local use. Never run this seeder against a production database.

### 4. Build the front end

```bash
npm run build        # production bundle
# or
npm run dev          # Vite dev server with hot reload, while developing
```

If the UI renders unstyled or you see a `ViteException: Unable to locate file in Vite manifest`,
one of these two commands has not run. See [Troubleshooting](troubleshooting.md).

### 5. Set up the Python `pdf-service`

```bash
cd pdf-service
python -m venv .venv

# Windows
.venv\Scripts\activate
# macOS / Linux
source .venv/bin/activate

pip install -r requirements.txt
cp .env.example .env
```

In `pdf-service/.env`, set `PDF_SERVICE_SECRET` to **exactly** the value you put in the root
`.env`. The service rejects any request whose `X-Pdf-Secret` header does not match.

Then start it:

```bash
uvicorn app.main:app --reload --port 8001
```

Check it is alive — the endpoint is secret-protected, so send the header:

```bash
curl -H "X-Pdf-Secret: your-secret" http://127.0.0.1:8001/health
# {"status":"ok","version":"0.1.0","environment":"local"}
```

### 6. Enable OCR (optional but recommended)

OCR and the scanned-PDF path of the Word export need one Tesseract language data file. Lapis
uses the Tesseract engine **bundled inside PyMuPDF**, so there is no system Tesseract or
Ghostscript to install — only the data file:

1. Download `eng.traineddata` from
   [tesseract-ocr/tessdata](https://github.com/tesseract-ocr/tessdata).
2. Put it in `pdf-service/tessdata/`.

See [`pdf-service/tessdata/README.md`](../pdf-service/tessdata/README.md) for other languages.
Without it, OCR requests return a clear 422 and the rest of the app keeps working.

### 7. Choose an AI backend (optional)

The assistant panel (chat, summarize, translate) needs a language model. The provider key lives
**only** in `pdf-service/.env` — never in Laravel and never in the browser.

| Backend | Set in `pdf-service/.env` | Notes |
|---|---|---|
| **Ollama** (default) | `AI_PROVIDER=ollama`, then `ollama pull qwen2.5:3b` | Local, offline, **no API key** |
| **Gemini** | `GEMINI_API_KEY=…` | Free tier at [Google AI Studio](https://aistudio.google.com/app/apikey) |
| **Claude** | `ANTHROPIC_API_KEY=…` | Optional third backend |

You can switch between Ollama and Gemini from a toggle inside the assistant panel without
restarting anything. Embeddings default to a deterministic local `hash` function that needs no
key at all.

**Without any LLM configured the app still runs.** Upload, viewing, page operations, the overlay
editor, forms, signatures, OCR, Word export and text extraction all work; only chat, summarize
and translate return an error.

### 8. Run everything

One command starts the web server, the queue worker, Vite and the Python service together:

```bash
composer run dev
```

Or run them in separate terminals:

```bash
php artisan serve                        # or use Laravel Herd
php artisan queue:listen --tries=1       # required for Word exports
npm run dev
cd pdf-service && uvicorn app.main:app --reload --port 8001
```

The **queue worker is not optional** if you want Word exports — they run as queued jobs.

With Herd the app is served at `https://pdf-editor.test`. Otherwise it is
`http://127.0.0.1:8000`.

---

## Option B — run the whole stack with Docker

Compose runs the app, a queue worker, the Python service and PostgreSQL together.

```bash
cp .env.example .env
```

Set these in `.env` before building:

```dotenv
APP_KEY=                # php artisan key:generate --show
PDF_SERVICE_SECRET=     # any long random string
DB_PASSWORD=            # doubles as the Postgres password inside the network
# Optional: GEMINI_API_KEY / ANTHROPIC_API_KEY (Ollama needs none)
```

Then:

```bash
docker compose build
docker compose up -d
docker compose exec app php artisan migrate --seed
```

Open <http://localhost:8080> (override the port with `APP_PORT`) and sign in with the demo
account above.

| Service | Image / build | Role |
|---|---|---|
| `db` | `pgvector/pgvector:pg18` | PostgreSQL; pgvector is available for a future upgrade |
| `app` | root `Dockerfile` (PHP-FPM + nginx + supervisor) | The Laravel web app |
| `queue` | same image as `app` | `php artisan queue:work` for OCR / export / AI jobs |
| `pdf-service` | `pdf-service/Dockerfile` | The Python FastAPI service |

Inside the network the services reach each other by name: `db:5432` and
`http://pdf-service:8001`.

Useful commands:

```bash
docker compose logs -f pdf-service    # follow one service's logs
docker compose exec app php artisan tinker
docker compose down                   # stop (add -v to also drop volumes and data)
```

Volumes persist the database, uploaded PDFs and the OCR cache. Mount your `tessdata/` directory
into the `pdf-service` container to enable OCR.

---

## Verify the install

```bash
php artisan test --compact                                   # Laravel suite
cd pdf-service && python -m pytest -q                        # Python suite
```

Both suites run fully offline — the Python service is faked in the Laravel tests and the LLM is
faked on both sides, so no API key or running service is needed. See [Testing](testing.md).

---

## Where to go next

- [User guide](user-guide.md) — walk through every feature with the demo library.
- [Architecture](architecture.md) — why the project is split into two services.
- [Troubleshooting](troubleshooting.md) — if something above did not work.
