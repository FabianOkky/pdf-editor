# Troubleshooting

Ordered roughly by how often each one bites.

---

## Every PDF operation fails

**Symptom** — uploads report "The PDF processing service is unavailable right now", or page
operations and exports all error.

**Cause** — Laravel cannot reach `pdf-service`, or the two disagree on the shared secret.

**Check**

```bash
curl -H "X-Pdf-Secret: your-secret" http://127.0.0.1:8001/health
```

| Result | Meaning | Fix |
|---|---|---|
| `{"status":"ok",…}` | The service is up and the secret is right | Check `PDF_SERVICE_URL` in the root `.env` |
| `401` | The secret does not match | Make `PDF_SERVICE_SECRET` byte-identical in `.env` and `pdf-service/.env` |
| Connection refused | The service is not running | Start it: `uvicorn app.main:app --reload --port 8001` |

After editing `.env`, run `php artisan config:clear`. A cached config will keep serving the old
value.

---

## Uploads are rejected

| Message | Meaning |
|---|---|
| "This PDF is password-protected…" | The file is encrypted. Remove the password and re-upload. |
| "This PDF has N pages, which exceeds…" | Over `PDF_MAX_PAGES` (default 500). |
| "The file may not be greater than…" | Over `PDF_MAX_UPLOAD_MB` (default 25). |
| "We could not read this PDF…" | Corrupt or not actually a PDF. |

Raising `PDF_MAX_UPLOAD_MB` also raises Livewire's temporary-upload cap, but your **web server**
has its own limit. For PHP, raise `upload_max_filesize` and `post_max_size`; for nginx,
`client_max_body_size`.

---

## Word exports never finish

**Symptom** — the modal sits at "queued" forever.

**Cause** — no queue worker.

```bash
php artisan queue:listen --tries=1     # development
php artisan queue:work                 # production
```

In Docker the `queue` service does this; check it is up with `docker compose ps`.

If the job moves to `failed` instead, the error is recorded on the row and shown in the modal.
`php artisan queue:failed` lists failures; `storage/logs/laravel.log` has the detail.

Large scanned documents legitimately take minutes — OCR renders and recognizes every page. The
job timeout is 600 seconds.

---

## OCR returns an error

**Symptom** — `422` with a message about missing language data.

**Cause** — no `tessdata` file.

**Fix** — download `eng.traineddata` from
[tesseract-ocr/tessdata](https://github.com/tesseract-ocr/tessdata) into `pdf-service/tessdata/`.
In Docker, put it on the host in the directory that is bind-mounted into the container.

You do **not** need to install Tesseract or Ghostscript — PyMuPDF ships the engine and needs
only the data file.

---

## A scanned PDF exports to an empty Word file

**Symptom** — the `.docx` downloads but has no text.

**Cause** — almost always missing `tessdata`. Without OCR there is nothing to extract, because
the pages are images.

Check the document's source type. If it is `scanned` or `mixed`, OCR must be available. Fix
`tessdata` as above and export again.

---

## The AI assistant errors out

| Message | Cause | Fix |
|---|---|---|
| "…API key is not configured" | The chosen backend has no key | Set it in `pdf-service/.env` — or switch the panel toggle to Ollama, which needs none |
| Connection error with Ollama | The daemon is not running | `ollama serve`, and `ollama pull qwen2.5:3b` once |
| Rate-limit message | Over `AI_RATE_LIMIT_PER_MINUTE` (default 15/min) | Wait, or raise it |
| Answers ignore the document | The document is not indexed | Re-open the assistant; indexing runs on first use and after the bytes change |

In Docker, Ollama runs on the **host**. `OLLAMA_URL` defaults to
`http://host.docker.internal:11434`, which works on Docker Desktop; on Linux, add an
`extra_hosts` entry or point it at the host IP.

**Answers are vague or cite the wrong pages.** The default embedding provider is `hash` — a
deterministic local function that matches lexically, not semantically. It is ideal for offline
use and tests, weaker for real retrieval. Switch `AI_EMBEDDING_PROVIDER` to `voyage` or `ollama`
for real semantic vectors, then **re-index**: vectors from different models are not comparable.

---

## The UI renders unstyled, or Vite throws

**Symptom** — `Illuminate\Foundation\ViteException: Unable to locate file in Vite manifest`, or
an unstyled page.

**Fix**

```bash
npm run build      # production bundle
# or
npm run dev        # dev server, while developing
```

If you changed Blade or Tailwind classes and see nothing, the dev server is not running or the
build is stale.

---

## Overlays land in the wrong place

**Symptom** — an edit appears somewhere other than where it was drawn, often on a rotated page.

This is the one class of bug the architecture is specifically designed to avoid, so it is worth
reporting. Geometry is stored in PDF points with a bottom-left origin and converted with PDF.js's
own viewport math, and baking un-rotates the page first. If placement is off:

1. Note the page rotation and the zoom the edit was made at.
2. Check `resources/js/pdf-editor/coords.js` for the conversion.
3. `tests/Feature/Documents/OverlayBakeTest.php` is where a reproduction belongs.

---

## Downloads hand back the original instead of my edits

**Expected behaviour** — "Download" bakes pending overlays first and gives you the edited file.
"Download original" deliberately gives the pristine upload.

If plain Download really is returning unedited bytes, that is a bug —
`tests/Feature/Documents/EditedOutputTest.php` exists precisely to prevent it, and it covers
download, export, page operations, split and merge.

---

## Tests fail locally

| Symptom | Fix |
|---|---|
| `database "pdf_editor_test" does not exist` | `createdb pdf_editor_test` |
| Connection refused | PostgreSQL is not running |
| One skipped test | Expected — it needs a real `tessdata` file, which is not committed |
| Pint reports style diffs | Run `vendor/bin/pint` (not `--test`) to fix them |
| PHPStan errors after a change | The project runs at level 7; add the missing types rather than a baseline entry |

The suites need no network and no running `pdf-service` — the service is faked. If a test seems
to want a live service, it is missing an `Http::fake()`.

---

## Storage or permission errors

```bash
php artisan storage:link           # only needed for the public disk
chmod -R 775 storage bootstrap/cache
```

PDFs live on the private `pdfs` disk (`storage/app/pdfs`) and are deliberately **not** served by
the web server — they are only reachable through an authorized controller action. If you can
fetch a PDF by URL without signing in, something is misconfigured.

---

## After changing configuration, nothing changes

```bash
php artisan config:clear
php artisan route:clear
php artisan view:clear
```

In production the entrypoint caches all three at boot, so a config change needs a restart.

---

## Still stuck

- `storage/logs/laravel.log` — Laravel errors
- `docker compose logs -f pdf-service` — Python errors
- Browser devtools console — PDF.js and editor errors

When reporting a problem, the useful details are: which of the two services failed, the document's
`source_type`, and whether `pdf-service` `/health` responds.
