"""Analyze a PDF: page count, per-page sizes, and native-vs-scanned detection.

``source_type`` is a cheap heuristic based on how many pages yield extractable text.
A page with little or no text is assumed to be a scanned image that would need OCR.
"""

from __future__ import annotations

import fitz

from app.services.pdf_document import open_pdf

# A page counts as "native" (real text) when it yields at least this many
# non-whitespace characters of extractable text. Below this we treat it as a
# scanned/image page.
TEXT_CHAR_THRESHOLD = 16


def analyze_pdf(data: bytes) -> dict:
    """Return ``{page_count, pages:[{width,height}], source_type}`` for the given PDF bytes.

    Raises:
        ValueError: for corrupt or encrypted PDFs (see :func:`open_pdf`).
    """
    pages: list[dict[str, float]] = []
    native_pages = 0

    with open_pdf(data) as doc:
        for page in doc:
            pages.append({"width": round(page.rect.width, 2), "height": round(page.rect.height, 2)})
            if _page_has_text(page):
                native_pages += 1
        page_count = doc.page_count

    return {
        "page_count": page_count,
        "pages": pages,
        "source_type": _classify(page_count, native_pages),
    }


def _page_has_text(page: fitz.Page) -> bool:
    """Whether a page has enough extractable text to be considered native."""
    text = page.get_text("text")
    non_whitespace = sum(1 for char in text if not char.isspace())

    return non_whitespace >= TEXT_CHAR_THRESHOLD


def _classify(page_count: int, native_pages: int) -> str:
    """Map the native-page tally onto a ``source_type`` label."""
    if page_count == 0:
        return "unknown"
    if native_pages == page_count:
        return "native"
    if native_pages == 0:
        return "scanned"

    return "mixed"
