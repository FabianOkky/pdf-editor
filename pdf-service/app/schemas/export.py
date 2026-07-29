"""Schemas for OCR and Word export (``POST /pdf/ocr`` and ``/pdf/export/docx``).

OCR returns a searchable PDF (the original image plus an invisible text layer) alongside the
recognized text. Word export returns a ``.docx`` plus metadata describing which pipeline ran
(native vs OCR-first) so the app can set honest, best-effort expectations in the UI.
"""

from __future__ import annotations

from pydantic import BaseModel


class OcrResponse(BaseModel):
    """A searchable PDF produced by OCR, with the recognized text and the language used."""

    page_count: int
    content_base64: str  # a searchable PDF: the page image + an invisible OCR text layer
    text: str
    language: str


class DocxExportResponse(BaseModel):
    """The ``.docx`` produced by the smart export, plus which pipeline path was taken."""

    source_type: str  # native | scanned | mixed | unknown
    ocr_applied: bool
    page_count: int
    content_base64: str  # the produced .docx (OpenXML word document)
