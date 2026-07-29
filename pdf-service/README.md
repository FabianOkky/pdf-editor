# PDF Service (Python / FastAPI)

Internal microservice for the PDF Editor app. Handles PDF byte operations (PyMuPDF), and
later OCR, Word export, and AI glue. The Laravel app calls it over localhost REST with a
shared-secret header (`X-Pdf-Secret`). **Bind to localhost only.**

## Requirements

- Python 3.12–3.14 (verified on 3.14; PyMuPDF, numpy, opencv, lxml all ship `cp314`/`abi3`
  wheels — no compiler needed).
- See `requirements.txt`. Word export adds `pdf2docx` (pulls in python-docx, numpy,
  opencv-python-headless, fonttools).

## OCR & Word export

OCR runs through **PyMuPDF's bundled Tesseract engine**, so there is **no system Tesseract or
Ghostscript to install** — only the Tesseract *language data* is needed. Download it once into
`tessdata/` (see `tessdata/README.md`):

```powershell
curl -L -o tessdata\eng.traineddata `
  https://github.com/tesseract-ocr/tessdata_fast/raw/main/eng.traineddata
```

- `POST /pdf/ocr` turns a scanned PDF into a *searchable* PDF + recognized text (cached by
  content hash under `.ocr_cache/`).
- `POST /pdf/export/docx` does the smart export: native PDFs go through `pdf2docx`
  (layout-aware); scanned/mixed PDFs are OCR'd first, then the text is laid out as an editable
  `.docx`. Without the language data, these endpoints return 422 and their tests are skipped.

## Setup (Windows / PowerShell)

```powershell
cd C:\Projects\pdf-editor\pdf-service
python -m venv .venv
.venv\Scripts\Activate.ps1          # if blocked: Set-ExecutionPolicy -Scope CurrentUser RemoteSigned
python -m pip install --upgrade pip
pip install -r requirements.txt
copy .env.example .env              # then edit PDF_SERVICE_SECRET to match Laravel
```

## Run

```powershell
uvicorn app.main:app --reload --port 8001
```

- Health check: `http://127.0.0.1:8001/health` (needs the `X-Pdf-Secret` header — Laravel
  sends it automatically; from a browser you'll get 401, which is expected).
- Interactive API docs: `http://127.0.0.1:8001/docs`.

## Test & format

```powershell
pytest          # run tests
ruff check .    # lint
black .         # format
```

## Layout

```
app/
  main.py        # FastAPI app + router registration
  core/          # config (env) + auth (shared secret)
  routers/       # one module per capability (health, …)
  schemas/       # pydantic request/response models
  services/      # business logic (PyMuPDF, OCR, docx, ai) — added per phase
tests/           # pytest (+ fixtures/ for sample PDFs, added later)
```

## Configuration

| Env var | Purpose | Default |
|---|---|---|
| `PDF_SERVICE_SECRET` | Shared secret; must match Laravel's `PDF_SERVICE_SECRET` | (empty → all requests 401) |
| `PDF_SERVICE_ENV` | Environment label | `local` |
| `PDF_SERVICE_VERSION` | Reported in `/health` | `0.1.0` |
| `PDF_SERVICE_NAME` | Reported in `/health` | `pdf-service` |
| `PDF_TESSDATA_PREFIX` | Folder holding `<lang>.traineddata` for OCR | `pdf-service/tessdata` |
| `PDF_OCR_LANGUAGE` | Default Tesseract language code | `eng` |
| `PDF_OCR_DPI` | Render DPI used when rasterizing pages for OCR | `200` |
| `PDF_OCR_CACHE_DIR` | Where OCR results are cached (by content hash) | `pdf-service/.ocr_cache` |
