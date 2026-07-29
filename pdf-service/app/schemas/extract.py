"""Schema for text extraction (``POST /pdf/extract-text``).

Returns the document's text both as a per-page mapping (so the RAG layer can cite page
numbers) and as one concatenated string. ``ocr_applied`` tells the caller whether the text
came from a scanned page run through OCR or straight from a native text layer.
"""

from __future__ import annotations

from pydantic import BaseModel


class PageText(BaseModel):
    """The extracted text of a single page (1-based)."""

    page_number: int
    text: str


class ExtractTextResponse(BaseModel):
    """Per-page + concatenated text for a PDF, plus how it was obtained."""

    page_count: int
    source_type: str  # native | scanned | mixed | unknown
    ocr_applied: bool
    pages: list[PageText]
    text: str
