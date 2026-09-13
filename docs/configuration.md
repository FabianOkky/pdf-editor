# Configuration

Lapis reads two environment files:

| File | Belongs to | Holds |
|---|---|---|
| `.env` (repository root) | The Laravel app | Database, storage, queue, upload limits, orchestration knobs |
| `pdf-service/.env` | The Python service | The shared secret, OCR settings, **and every AI provider key** |

Copy each from its `.env.example` and fill it in. Neither file is committed.

> **The one rule that trips people up:** `PDF_SERVICE_SECRET` must be **byte-identical** in both
> files. If it is not, every PDF operation fails with a `401`.

> **Provider API keys belong in `pdf-service/.env` only.** Laravel never holds them, and they
> never reach the browser. The AI-related values in the root `.env` are display labels and
> orchestration limits.

---

## Laravel — root `.env`

### Application

| Variable | Default | Notes |
|---|---|---|
| `APP_NAME` | `Lapis` | Shown in the UI and in mail. |
| `APP_ENV` | `local` | Use `production` when deploying. |
| `APP_KEY` | — | **Required.** Generate with `php artisan key:generate`. |
| `APP_DEBUG` | `true` | **Must be `false` in production.** |
| `APP_URL` | `http://localhost` | Used for generated links. |
| `APP_LOCALE` | `en` | |

### Database

| Variable | Default | Notes |
|---|---|---|
| `DB_CONNECTION` | `pgsql` | PostgreSQL is the supported database. |
| `DB_HOST` | `127.0.0.1` | `db` inside Docker Compose. |
| `DB_PORT` | `5432` | |
| `DB_DATABASE` | `pdf_editor` | The test suite uses `pdf_editor_test`. |
| `DB_USERNAME` | `postgres` | |
| `DB_PASSWORD` | — | In Docker Compose this doubles as the Postgres password. |

### Storage, sessions, queue

| Variable | Default | Notes |
|---|---|---|
| `FILESYSTEM_DISK` | `local` | PDFs use the dedicated private `pdfs` disk regardless. |
| `SESSION_DRIVER` | `database` | |
| `QUEUE_CONNECTION` | `database` | A worker must run for Word exports. |
| `CACHE_STORE` | `database` | |

Uploaded PDFs live on the `pdfs` disk — `storage/app/pdfs`, private, never web-served. Files are
only reachable through an authorized controller action.

### PDF microservice

| Variable | Default | Notes |
|---|---|---|
| `PDF_SERVICE_URL` | `http://127.0.0.1:8001` | `http://pdf-service:8001` inside Docker. |
| `PDF_SERVICE_SECRET` | — | **Required.** Must match `pdf-service/.env`. |
| `PDF_SERVICE_TIMEOUT` | `30` | Seconds, for normal calls. |
| `PDF_SERVICE_EXPORT_TIMEOUT` | `300` | Seconds, for OCR and Word export inside a queued job. |

### Upload limits

| Variable | Default | Notes |
|---|---|---|
| `PDF_MAX_UPLOAD_MB` | `25` | Also raises Livewire's temporary-upload cap. |
| `PDF_MAX_PAGES` | `500` | `0` disables the page cap. |

### AI orchestration

These are Laravel-side knobs. They never contain a credential.

| Variable | Default | Notes |
|---|---|---|
| `AI_DEFAULT_PROVIDER` | `ollama` | Which backend the panel toggle starts on. |
| `AI_MODEL` | `qwen2.5:3b` | Display-only fallback label. |
| `OLLAMA_MODEL` | `qwen2.5:3b` | Display-only label for the Ollama option. |
| `GEMINI_MODEL` | `gemini-2.0-flash` | Display-only label for the Gemini option. |
| `AI_EMBEDDING_PROVIDER` | `hash` | Mirrors the service setting so the UI can describe it. |
| `AI_CHUNK_SIZE` | `1200` | Target characters per RAG chunk. |
| `AI_CHUNK_OVERLAP` | `200` | Characters carried between adjacent chunks. |
| `AI_RETRIEVAL_TOP_K` | `5` | Chunks fed to the model as grounding context. |
| `AI_MAX_INPUT_CHARS` | `40000` | Cap on text sent to summarize / translate. |
| `AI_RATE_LIMIT_PER_MINUTE` | `15` | Per-user cap on AI actions. |
| `AI_OCR_LANGUAGE` | `eng` | Tesseract language used for AI text extraction. |

> The model names above are **labels**. The model that actually runs is configured in
> `pdf-service/.env`. Keep them in sync or the footer will misreport.

### Docker Compose only

| Variable | Default | Notes |
|---|---|---|
| `APP_PORT` | `8080` | Host port for the app container. |
| `FORWARD_DB_PORT` | `5432` | Host port for Postgres. |

---

## Python service — `pdf-service/.env`

### Core

| Variable | Default | Notes |
|---|---|---|
| `PDF_SERVICE_SECRET` | — | **Required.** Must match the root `.env`. |
| `PDF_SERVICE_ENV` | `local` | `local` also serves interactive docs at `/docs`. |
| `PDF_SERVICE_VERSION` | `0.1.0` | Reported by `/health`. |

### OCR

| Variable | Default | Notes |
|---|---|---|
| `PDF_TESSDATA_PREFIX` | `./tessdata` | Where `<lang>.traineddata` files live. |
| `PDF_OCR_LANGUAGE` | `eng` | Default Tesseract language. |
| `PDF_OCR_DPI` | `200` | Higher is more accurate and slower. |
| `PDF_OCR_CACHE_DIR` | `./.ocr_cache` | Results cached by `sha256(bytes + language)`. |

OCR uses the Tesseract engine bundled with PyMuPDF — there is no system Tesseract or
Ghostscript to install, only the language data file.

### LLM provider

`AI_PROVIDER` is the **fallback** backend, used only when Laravel sends no explicit provider.
The assistant panel's toggle wins per request.

| Variable | Default | Notes |
|---|---|---|
| `AI_PROVIDER` | `ollama` | `anthropic` · `gemini` · `ollama` |
| `AI_MAX_TOKENS` | `2048` | Ceiling on generated length. |

**Ollama** — local, offline, no API key:

| Variable | Default |
|---|---|
| `OLLAMA_URL` | `http://127.0.0.1:11434` |
| `OLLAMA_MODEL` | `qwen2.5:3b` |

Run `ollama pull qwen2.5:3b` once before first use.

**Google Gemini** — free tier at [Google AI Studio](https://aistudio.google.com/app/apikey):

| Variable | Default |
|---|---|
| `GEMINI_API_KEY` | — |
| `GEMINI_MODEL` | `gemini-2.0-flash` |

**Anthropic Claude**:

| Variable | Default |
|---|---|
| `ANTHROPIC_API_KEY` | — |
| `AI_MODEL` | — |

### Embeddings

| Variable | Default | Notes |
|---|---|---|
| `AI_EMBEDDING_PROVIDER` | `hash` | `hash` · `voyage` · `ollama` |
| `AI_EMBEDDING_DIM` | `256` | Used by the `hash` provider. |
| `AI_EMBEDDING_MODEL` | `voyage-3.5-lite` | For the Voyage provider. |
| `VOYAGE_API_KEY` | — | Required for `voyage`. |
| `OLLAMA_EMBEDDING_MODEL` | `nomic-embed-text` | For local embeddings. |

`hash` is a deterministic local function: no key, no network, reproducible. It gives lexical
rather than semantic matching, which is fine for demos and for tests but weaker for real
retrieval. `voyage` and `ollama` produce real semantic vectors.

> **Switching embedding providers requires a re-index.** Vectors from different models are not
> comparable. `document_chunks.embedding_model` records which produced each row.

---

## What runs without what

| You want | You need |
|---|---|
| Upload, library, viewer | Laravel + database + `pdf-service` |
| Page operations, overlay editor, forms, signatures | Same |
| OCR, and the scanned path of Word export | A `tessdata/<lang>.traineddata` file |
| Word export | A running queue worker |
| Chat, summarize, translate | An LLM backend in `pdf-service/.env` |

Nothing above cascades into a hard failure. Without an LLM the assistant's three actions error
out and everything else is untouched. Without OCR data, OCR requests return a clear `422`.

---

## Production checklist

- [ ] `APP_ENV=production`, `APP_DEBUG=false`
- [ ] A real `APP_KEY`
- [ ] A long random `PDF_SERVICE_SECRET`, identical in both files
- [ ] A strong `DB_PASSWORD`
- [ ] `pdf-service` unreachable from the public internet
- [ ] HTTPS in front of the app
- [ ] A persistent volume for `storage/app/pdfs`
- [ ] A queue worker running under a supervisor
- [ ] `config:cache`, `route:cache`, `view:cache` (the Docker entrypoint does this)

See [Deployment](deployment.md) for the full picture.
