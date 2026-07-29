# Tesseract language data (OCR)

The PDF service does OCR through **PyMuPDF's bundled Tesseract engine**, so there is **no
system Tesseract or Ghostscript to install** — only the Tesseract *language data* files belong
here. The `.traineddata` files are **not committed** (they are large binaries); download the
ones you need into this folder.

English (the default, `PDF_OCR_LANGUAGE=eng`):

```bash
# from the repo root
curl -L -o pdf-service/tessdata/eng.traineddata \
  https://github.com/tesseract-ocr/tessdata_fast/raw/main/eng.traineddata
```

Add more languages by downloading the matching `<lang>.traineddata` (e.g. `ind.traineddata`
for Indonesian) into this same folder and passing that code as the `language` parameter.

Override the location with `PDF_TESSDATA_PREFIX` if you keep the data elsewhere. When the data
for a requested language is missing, OCR-dependent endpoints return HTTP 422 and the
OCR/export tests are skipped.
