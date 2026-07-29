"""Text extraction for the AI/RAG layer (``POST /pdf/extract-text``).

Native pages are read straight from the PDF's text layer (fast, lossless). Scanned/mixed
documents are run through OCR first so their words are recoverable — exactly the step naive
extractors skip. This reuses the Phase 5 OCR engine (PyMuPDF's bundled Tesseract); when the
language data is unavailable the call degrades gracefully to whatever native text exists
instead of failing, so the assistant still works on native PDFs without any OCR setup.

The input bytes are never mutated (the Golden Rule).
"""

from __future__ import annotations

import base64

from app.services.pdf_document import open_pdf
from app.services.pdf_info import TEXT_CHAR_THRESHOLD
from app.services.pdf_ocr import ocr_available, ocr_pdf


def extract_text(data: bytes, language: str | None = None) -> dict:
    """Return ``{page_count, source_type, ocr_applied, pages, text}`` for the given PDF bytes.

    Raises:
        ValueError: for corrupt or encrypted PDFs (see :func:`open_pdf`).
    """
    native_pages = _native_pages(data)
    page_count = len(native_pages)
    source_type = _classify(native_pages)

    needs_ocr = source_type in ("scanned", "mixed")
    ocr_applied = False

    if needs_ocr and ocr_available(language):
        pages = _ocr_pages(data, language)
        ocr_applied = True
    else:
        pages = native_pages

    return {
        "page_count": page_count,
        "source_type": source_type,
        "ocr_applied": ocr_applied,
        "pages": [{"page_number": i + 1, "text": text} for i, text in enumerate(pages)],
        "text": "\n\n".join(text for text in pages if text).strip(),
    }


def _native_pages(data: bytes) -> list[str]:
    """The per-page text of the PDF's native text layer (empty string for image-only pages)."""
    with open_pdf(data) as doc:
        return [page.get_text("text").strip() for page in doc]


def _ocr_pages(data: bytes, language: str | None) -> list[str]:
    """OCR the whole document and return its per-page recognized text.

    ``ocr_pdf`` is content-hash cached, so this reuses any prior OCR of the same bytes.
    """
    result = ocr_pdf(data, language=language)
    searchable = base64.b64decode(result["content_base64"])
    with open_pdf(searchable) as doc:
        return [page.get_text("text").strip() for page in doc]


def _classify(pages: list[str]) -> str:
    """Map native-page text counts onto a ``source_type`` label (mirrors ``pdf_info``)."""
    page_count = len(pages)
    if page_count == 0:
        return "unknown"

    native = sum(
        1 for text in pages if sum(1 for c in text if not c.isspace()) >= TEXT_CHAR_THRESHOLD
    )
    if native == page_count:
        return "native"
    if native == 0:
        return "scanned"

    return "mixed"
