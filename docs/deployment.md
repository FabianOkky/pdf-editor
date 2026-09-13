# Deployment

Lapis ships two processes plus a queue worker and a database. Any deployment has to place four
things:

1. the **Laravel app** (HTTP),
2. a **queue worker** (Word exports),
3. the **`pdf-service`** (private, never publicly routable),
4. **PostgreSQL** and a **persistent volume** for uploaded files.

---

## Option 1 — a VPS with Docker Compose

The simplest real host. The repository's `docker-compose.yml` already wires the four pieces
together.

```bash
git clone https://github.com/FabianOkky/pdf-editor.git
cd pdf-editor
cp .env.example .env
```

Set in `.env`:

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_KEY=base64:…            # php artisan key:generate --show
APP_URL=https://your-domain

PDF_SERVICE_SECRET=…        # long and random
DB_PASSWORD=…               # strong; also the Postgres password inside the network

# Optional AI backend
AI_DEFAULT_PROVIDER=gemini
GEMINI_API_KEY=…
```

Then:

```bash
docker compose build
docker compose up -d
docker compose exec app php artisan migrate --force
```

Do **not** run `--seed` in production — the seeder creates a demo account with a known
password.

### Updating

```bash
git pull
docker compose up -d --build
docker compose exec app php artisan migrate --force
```

### TLS

The `app` container listens on `8080` over plain HTTP. Put a reverse proxy in front of it.
Caddy is the least work:

```caddyfile
your-domain.com {
    reverse_proxy 127.0.0.1:8080
}
```

Caddy obtains and renews certificates automatically. nginx with certbot, or a cloud load
balancer, work equally well.

### What is already handled

- The `app` image runs nginx + PHP-FPM under supervisor.
- Its entrypoint runs `config:cache`, `route:cache` and `view:cache` on boot.
- `queue` runs `php artisan queue:work --tries=1 --timeout=600`.
- `pdf-service` is declared with `expose`, not `ports` — it is reachable only inside the
  Compose network.
- Named volumes persist the database (`db-data`), uploads (`app-storage`) and the OCR cache
  (`pdf-ocr-cache`).

### OCR in Docker

`./pdf-service/tessdata` is bind-mounted into the container. Put `eng.traineddata` (and any
other languages) there on the host before starting, or OCR will return a clear `422`.

### Using Ollama with Docker

The Ollama daemon runs on the **host**, not in the Compose network. The default
`OLLAMA_URL` is `http://host.docker.internal:11434`, which works on Docker Desktop. On Linux,
either add an `extra_hosts` entry mapping `host.docker.internal` to `host-gateway`, or set
`OLLAMA_URL` to the host's IP.

---

## Option 2 — managed split

Fewer servers to babysit, more moving parts to wire.

| Piece | Where |
|---|---|
| Laravel app + queue | [Laravel Cloud](https://cloud.laravel.com) |
| `pdf-service` | A container host — Fly.io, Render, Railway (build from `pdf-service/Dockerfile`) |
| PostgreSQL | The platform's managed database |
| Uploaded files | S3-compatible object storage |

Three things need attention:

1. **Keep `pdf-service` private.** Use the platform's private networking. If it must be public,
   it is protected only by the shared secret — so make the secret long and rotate it.
2. **Switch the `pdfs` disk to S3.** The default is a local disk, which does not survive a
   container restart on most platforms. Point `config/filesystems.php`'s `pdfs` disk at S3 and
   keep `visibility: private`.
3. **Run the queue worker as its own process.** Without it, Word exports queue forever.

---

## Production hardening

### Required

- [ ] `APP_ENV=production` and `APP_DEBUG=false` — debug mode leaks environment variables on any error page.
- [ ] A unique `APP_KEY`, not one copied from anywhere.
- [ ] A long random `PDF_SERVICE_SECRET`, identical on both sides.
- [ ] A strong `DB_PASSWORD`.
- [ ] HTTPS, with HTTP redirected to it.
- [ ] `pdf-service` unreachable from the public internet.
- [ ] A persistent volume or object store for uploads — losing it loses user documents.

### Recommended

- [ ] Back up the database **and** the uploads volume. Either alone is useless.
- [ ] Watch disk usage: originals, every version, exports and the OCR cache all accumulate.
- [ ] Tune `PDF_MAX_UPLOAD_MB` and `PDF_MAX_PAGES` to what your host can afford.
- [ ] Lower `AI_RATE_LIMIT_PER_MINUTE` if an LLM backend is billed per token.
- [ ] Ship logs somewhere durable; container logs vanish with the container.
- [ ] Keep PyMuPDF patched — it parses untrusted input.

### Resource notes

OCR is the expensive operation: it renders every page at `PDF_OCR_DPI` (default 200) and runs
Tesseract over each one. A 50-page scanned document takes minutes and a lot of RAM, which is
exactly why export is asynchronous with a 600-second job timeout. Size the `pdf-service` and
`queue` containers for that peak, not for the average.

The OCR cache is keyed by content hash, so repeat work is free — give it a persistent volume.

---

## Health checks

```bash
# Laravel
curl -f https://your-domain/ || echo "app down"

# pdf-service, from inside the network
curl -f -H "X-Pdf-Secret: $PDF_SERVICE_SECRET" http://pdf-service:8001/health
```

`/health` returns `{"status":"ok","service":"pdf-service","version":"…"}`. It requires the
secret, so it doubles as a check that both sides agree on it.

For Compose, `db` already has a health check and `app` waits on it.

---

## Backups

Two things must be backed up together, or a restore will produce documents whose rows point at
missing files:

```bash
# Database
docker compose exec db pg_dump -U postgres pdf_editor > backup-$(date +%F).sql

# Uploads
docker run --rm -v pdf-editor_app-storage:/data -v "$PWD:/backup" \
  alpine tar czf /backup/uploads-$(date +%F).tar.gz /data
```

The OCR cache does not need backing up — it regenerates.

---

## Troubleshooting a deployment

See [Troubleshooting](troubleshooting.md). The two most common production failures:

- **Everything PDF-related returns an error** — the two `PDF_SERVICE_SECRET` values differ, or
  `PDF_SERVICE_URL` is wrong. Check with the `/health` call above.
- **Exports stay "queued" forever** — no queue worker is running.
