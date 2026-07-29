"""OCR via PyMuPDF's bundled Tesseract engine (``POST /pdf/ocr``).

PyMuPDF's wheels ship MuPDF compiled with Tesseract, so OCR needs only the Tesseract
*language data* (e.g. ``eng.traineddata``) — there is no separate Tesseract or Ghostscript
install. Point ``tessdata_prefix`` (config) at the folder holding the ``.traineddata`` files.

Each page is rendered to an image and OCR'd into a one-page searchable PDF (the image plus an
invisible text layer); the pages are concatenated into a full searchable PDF. Results are
cached on disk keyed by a hash of the input bytes + language, so repeat calls (e.g. OCR then
Word export of the same document) reuse the first run — the pipeline is idempotent. The input
bytes are never mutated (the Golden Rule).
"""

from __future__ import annotations

import base64
import hashlib
from pathlib import Path

import fitz

from app.core.config import get_settings
from app.services.pdf_document import open_pdf


def _resolve_tessdata(tessdata: str | None) -> str:
    """The tessdata directory to use (explicit override, else the configured default)."""
    return tessdata if tessdata is not None else get_settings().tessdata_prefix


def ocr_available(language: str | None = None, tessdata: str | None = None) -> bool:
    """Whether OCR can run: the language's ``.traineddata`` file is present.

    PyMuPDF bundles the Tesseract engine, so the only external requirement is the language
    data file. This is a cheap, side-effect-free probe used by tests and by the export
    pipeline to decide whether the scanned path is usable.
    """
    language = language or get_settings().ocr_language

    return (Path(_resolve_tessdata(tessdata)) / f"{language}.traineddata").is_file()


def ocr_searchable_pdf(
    data: bytes, language: str | None = None, tessdata: str | None = None
) -> bytes:
    """OCR every page and return a new searchable PDF (image + invisible text layer).

    Raises:
        ValueError: for unreadable PDFs, or when the language data is unavailable.
    """
    settings = get_settings()
    language = language or settings.ocr_language
    directory = _resolve_tessdata(tessdata)

    if not ocr_available(language, directory):
        raise ValueError(
            f"OCR language data '{language}' not found in '{directory}'. "
            "See pdf-service/README.md for the one-time tessdata setup."
        )

    with open_pdf(data) as doc:
        out = fitz.open()
        try:
            for page in doc:
                pixmap = page.get_pixmap(dpi=settings.ocr_dpi)
                page_pdf_bytes = pixmap.pdfocr_tobytes(language=language, tessdata=directory)
                with fitz.open("pdf", page_pdf_bytes) as page_pdf:
                    out.insert_pdf(page_pdf)
            searchable = out.tobytes(deflate=True, garbage=3)
        finally:
            out.close()

    return searchable


def ocr_pdf(
    data: bytes,
    language: str | None = None,
    tessdata: str | None = None,
    cache_dir: str | None = None,
) -> dict:
    """Return ``{page_count, content_base64, text, language}`` — a searchable PDF + its text.

    Idempotent: results are cached by a hash of the input bytes + language, so repeated calls
    reuse the first run. Caching is best-effort — a missing/unwritable cache directory simply
    re-runs OCR.

    Raises:
        ValueError: for unreadable PDFs, or when the language data is unavailable.
    """
    settings = get_settings()
    language = language or settings.ocr_language
    cache_directory = cache_dir if cache_dir is not None else settings.ocr_cache_dir

    cached = _read_cache(data, language, cache_directory)
    if cached is not None:
        searchable, text = cached
    else:
        searchable = ocr_searchable_pdf(data, language, tessdata)
        text = _extract_text(searchable)
        _write_cache(data, language, cache_directory, searchable, text)

    return {
        "page_count": _page_count(searchable),
        "content_base64": base64.b64encode(searchable).decode("ascii"),
        "text": text,
        "language": language,
    }


def _extract_text(pdf_bytes: bytes) -> str:
    """Concatenate the extractable text of every page of a (searchable) PDF."""
    parts: list[str] = []
    with open_pdf(pdf_bytes) as doc:
        for page in doc:
            parts.append(page.get_text("text"))

    return "\n".join(parts).strip()


def _page_count(pdf_bytes: bytes) -> int:
    """Page count of the given PDF bytes."""
    with open_pdf(pdf_bytes) as doc:
        return doc.page_count


def _cache_key(data: bytes, language: str) -> str:
    """A content hash keying the OCR cache on the input bytes + language."""
    digest = hashlib.sha256()
    digest.update(language.encode("utf-8"))
    digest.update(b"\0")
    digest.update(data)

    return digest.hexdigest()


def _read_cache(data: bytes, language: str, cache_dir: str) -> tuple[bytes, str] | None:
    """Return the cached ``(searchable_pdf, text)`` for these bytes, or ``None`` on a miss."""
    if not cache_dir:
        return None

    key = _cache_key(data, language)
    pdf_path = Path(cache_dir) / f"{key}.pdf"
    text_path = Path(cache_dir) / f"{key}.txt"

    if not (pdf_path.is_file() and text_path.is_file()):
        return None

    try:
        return pdf_path.read_bytes(), text_path.read_text(encoding="utf-8")
    except OSError:
        return None


def _write_cache(data: bytes, language: str, cache_dir: str, searchable: bytes, text: str) -> None:
    """Store the OCR result for these bytes. Best-effort: filesystem errors are swallowed."""
    if not cache_dir:
        return

    try:
        directory = Path(cache_dir)
        directory.mkdir(parents=True, exist_ok=True)
        key = _cache_key(data, language)
        (directory / f"{key}.pdf").write_bytes(searchable)
        (directory / f"{key}.txt").write_text(text, encoding="utf-8")
    except OSError:
        return
