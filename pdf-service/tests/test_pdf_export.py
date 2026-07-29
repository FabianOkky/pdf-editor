"""Tests for the smart Word export endpoint (``/pdf/export/docx``).

The pipeline must: convert a native PDF straight through pdf2docx; route a scanned PDF through
OCR *first* so its text is recoverable; and report which path ran. The native path and the
routing decision are tested without the OCR engine (the engine is faked), so they always run;
the real scanned → ``.docx`` proof is skipped when tessdata is absent. Inputs are never mutated.
"""

from __future__ import annotations

import base64
import io
import zipfile

import fitz
import pytest
from docx import Document as DocxDocument

from app.services import pdf_export
from app.services.pdf_ocr import ocr_available

requires_ocr = pytest.mark.skipif(
    not ocr_available(),
    reason="OCR language data (tessdata) not installed; see pdf-service/README.md.",
)


def _export(client, secret, pdf: bytes, language: str | None = None):
    data = {"language": language} if language is not None else None
    return client.post(
        "/pdf/export/docx",
        headers={"X-Pdf-Secret": secret},
        files={"file": ("doc.pdf", pdf, "application/pdf")},
        data=data,
    )


def _docx_text(raw: bytes) -> str:
    """Extract the visible paragraph text from a ``.docx`` byte string."""
    document = DocxDocument(io.BytesIO(raw))
    return "\n".join(paragraph.text for paragraph in document.paragraphs)


def _one_page_pdf(text: str) -> bytes:
    doc = fitz.open()
    doc.new_page(width=612, height=792).insert_text((72, 72), text, fontsize=14)
    data = doc.tobytes()
    doc.close()
    return data


def test_export_requires_secret(client, native_pdf):
    response = client.post(
        "/pdf/export/docx",
        files={"file": ("doc.pdf", native_pdf, "application/pdf")},
    )
    assert response.status_code == 401


def test_export_rejects_a_corrupt_pdf(client, secret):
    assert _export(client, secret, b"definitely not a pdf").status_code == 422


def test_export_native_pdf_produces_editable_docx(client, secret, native_pdf):
    response = _export(client, secret, native_pdf)
    assert response.status_code == 200

    body = response.json()
    assert body["source_type"] == "native"
    assert body["ocr_applied"] is False
    assert body["page_count"] == 2

    raw = base64.b64decode(body["content_base64"])
    assert zipfile.is_zipfile(io.BytesIO(raw))  # a .docx is an OpenXML zip package
    assert "Hello world" in _docx_text(raw)


def test_export_native_skips_ocr(client, secret, native_pdf, monkeypatch):
    """A native PDF must never hit the (expensive) OCR step."""

    def must_not_run(*args, **kwargs):
        raise AssertionError("OCR must not run for a native PDF")

    monkeypatch.setattr(pdf_export, "ocr_pdf", must_not_run)

    body = _export(client, secret, native_pdf).json()
    assert body["ocr_applied"] is False


def test_export_routes_scanned_through_ocr_first(client, secret, scanned_pdf, monkeypatch):
    """A scanned PDF is OCR'd first; the OCR output (not the original) feeds pdf2docx."""
    calls = {"count": 0}

    def fake_ocr(data, language=None, tessdata=None, cache_dir=None):
        calls["count"] += 1
        searchable = _one_page_pdf("FAKEOCR sentinel 4815")
        return {
            "page_count": 1,
            "content_base64": base64.b64encode(searchable).decode("ascii"),
            "text": "FAKEOCR sentinel 4815",
            "language": "eng",
        }

    monkeypatch.setattr(pdf_export, "ocr_pdf", fake_ocr)

    body = _export(client, secret, scanned_pdf).json()
    assert body["source_type"] == "scanned"
    assert body["ocr_applied"] is True
    assert calls["count"] == 1
    # The faked OCR text flowed into the produced .docx, proving OCR-first wiring.
    assert "FAKEOCR" in _docx_text(base64.b64decode(body["content_base64"]))


@requires_ocr
def test_export_scanned_pdf_recovers_text_with_real_ocr(client, secret, scanned_text_pdf):
    response = _export(client, secret, scanned_text_pdf)
    assert response.status_code == 200

    body = response.json()
    assert body["source_type"] == "scanned"
    assert body["ocr_applied"] is True

    text = _docx_text(base64.b64decode(body["content_base64"]))
    # The whole point: a scanned doc that naive converters leave empty comes back with text.
    assert "4815" in text


def _empty_docx_bytes() -> bytes:
    document = DocxDocument()
    buffer = io.BytesIO()
    document.save(buffer)
    return buffer.getvalue()


def test_recover_dropped_text_appends_text_missing_from_the_docx():
    """When the DOCX lacks source text, the recovery step appends it (no content lost)."""
    pdf = _one_page_pdf("Margomulyo sentinel 73519")

    recovered = pdf_export._recover_dropped_text(pdf, _empty_docx_bytes())

    text = _docx_text(recovered)
    assert "Recovered text" in text
    assert "Margomulyo sentinel 73519" in text


def test_recover_dropped_text_recovers_partial_line_drops():
    """Even a few words dropped from a mostly-present line are recovered (not just whole lines)."""
    pdf = _one_page_pdf("Alpha Bravo Charlie Delta Echo")

    document = DocxDocument()
    document.add_paragraph("Alpha Bravo Delta")  # "Charlie" and "Echo" were dropped
    buffer = io.BytesIO()
    document.save(buffer)

    recovered = pdf_export._recover_dropped_text(pdf, buffer.getvalue())

    text = _docx_text(recovered)
    assert "Recovered text" in text
    assert "Charlie" in text
    assert "Echo" in text


def test_recover_dropped_text_is_a_noop_when_all_text_is_present():
    """When every source word already appears in the DOCX, the bytes are returned unchanged."""
    pdf = _one_page_pdf("Margomulyo sentinel 73519")

    document = DocxDocument()
    document.add_paragraph("Margomulyo sentinel 73519")
    buffer = io.BytesIO()
    document.save(buffer)
    docx_bytes = buffer.getvalue()

    assert pdf_export._recover_dropped_text(pdf, docx_bytes) == docx_bytes
