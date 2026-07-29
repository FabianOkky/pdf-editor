"""Tests for text extraction (``/pdf/extract-text``).

Native extraction must work with no OCR setup. Scanned/mixed documents route through OCR
first (the engine is faked, so the test always runs); when OCR is unavailable the endpoint
degrades to the native text instead of failing. Inputs are never mutated.
"""

from __future__ import annotations

import base64

import fitz

from app.services import pdf_extract


def _extract(client, secret, pdf: bytes, language: str | None = None):
    data = {"language": language} if language is not None else None
    return client.post(
        "/pdf/extract-text",
        headers={"X-Pdf-Secret": secret},
        files={"file": ("doc.pdf", pdf, "application/pdf")},
        data=data,
    )


def _one_page_pdf(text: str) -> bytes:
    doc = fitz.open()
    doc.new_page(width=612, height=792).insert_text((72, 72), text, fontsize=14)
    data = doc.tobytes()
    doc.close()
    return data


def test_extract_requires_secret(client, native_pdf):
    response = client.post(
        "/pdf/extract-text",
        files={"file": ("doc.pdf", native_pdf, "application/pdf")},
    )
    assert response.status_code == 401


def test_extract_rejects_a_corrupt_pdf(client, secret):
    assert _extract(client, secret, b"not a pdf").status_code == 422


def test_extract_native_pdf_returns_per_page_text(client, secret, native_pdf):
    body = _extract(client, secret, native_pdf).json()

    assert body["source_type"] == "native"
    assert body["ocr_applied"] is False
    assert body["page_count"] == 2
    assert len(body["pages"]) == 2
    assert body["pages"][0]["page_number"] == 1
    assert "Hello world" in body["pages"][0]["text"]
    assert "Hello world" in body["text"]


def test_extract_routes_scanned_through_ocr_first(client, secret, scanned_pdf, monkeypatch):
    """A scanned PDF is OCR'd first; the recognized text feeds the per-page mapping."""
    monkeypatch.setattr(pdf_extract, "ocr_available", lambda language=None: True)

    def fake_ocr(data, language=None, tessdata=None, cache_dir=None):
        searchable = _one_page_pdf("FAKEOCR sentinel 4815")
        return {
            "page_count": 1,
            "content_base64": base64.b64encode(searchable).decode("ascii"),
            "text": "FAKEOCR sentinel 4815",
            "language": "eng",
        }

    monkeypatch.setattr(pdf_extract, "ocr_pdf", fake_ocr)

    body = _extract(client, secret, scanned_pdf).json()
    assert body["source_type"] == "scanned"
    assert body["ocr_applied"] is True
    assert "FAKEOCR" in body["pages"][0]["text"]
    assert "FAKEOCR" in body["text"]


def test_extract_scanned_without_ocr_degrades_gracefully(client, secret, scanned_pdf, monkeypatch):
    """With no OCR language data, a scanned PDF returns its (empty) native text, not an error."""
    monkeypatch.setattr(pdf_extract, "ocr_available", lambda language=None: False)

    body = _extract(client, secret, scanned_pdf).json()
    assert body["source_type"] == "scanned"
    assert body["ocr_applied"] is False
    assert body["page_count"] == 1
    # The image-only page has no extractable text; the endpoint still responds cleanly.
    assert body["pages"][0]["text"] == ""
