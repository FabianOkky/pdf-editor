"""Tests for the OCR endpoint (``/pdf/ocr``).

OCR must turn an image-only (scanned) PDF into a *searchable* PDF whose text layer contains the
recognized words, and return that text. The heavy, engine-dependent cases are skipped when the
Tesseract language data is not installed (see the README); the cache and auth behaviour are
verified without the engine so they always run. The input bytes are never mutated.
"""

from __future__ import annotations

import base64

import fitz
import pytest

from app.services import pdf_ocr
from app.services.pdf_ocr import ocr_available, ocr_pdf
from tests.conftest import OCR_SAMPLE_TEXT

requires_ocr = pytest.mark.skipif(
    not ocr_available(),
    reason="OCR language data (tessdata) not installed; see pdf-service/README.md.",
)


def _ocr(client, secret, pdf: bytes, language: str | None = None):
    data = {"language": language} if language is not None else None
    return client.post(
        "/pdf/ocr",
        headers={"X-Pdf-Secret": secret},
        files={"file": ("doc.pdf", pdf, "application/pdf")},
        data=data,
    )


def test_ocr_requires_secret(client, scanned_text_pdf):
    response = client.post(
        "/pdf/ocr",
        files={"file": ("doc.pdf", scanned_text_pdf, "application/pdf")},
    )
    assert response.status_code == 401


def test_ocr_rejects_a_corrupt_pdf(client, secret):
    response = _ocr(client, secret, b"not a pdf at all")
    assert response.status_code == 422


@requires_ocr
def test_ocr_recovers_text_from_a_scanned_pdf(client, secret, scanned_text_pdf):
    # The scanned fixture has no extractable text up front — proving OCR did the work.
    blank = fitz.open(stream=scanned_text_pdf, filetype="pdf")
    assert blank[0].get_text().strip() == ""
    blank.close()

    response = _ocr(client, secret, scanned_text_pdf)
    assert response.status_code == 200

    body = response.json()
    assert body["page_count"] == 1
    assert body["language"] == "eng"
    # Both numbers from the rendered phrase should be recognized.
    assert "4815" in body["text"]
    assert "162342" in body["text"]

    # The returned PDF is genuinely searchable: its text layer carries the recognized words.
    searchable = base64.b64decode(body["content_base64"])
    doc = fitz.open(stream=searchable, filetype="pdf")
    layer_text = doc[0].get_text()
    doc.close()
    assert "4815" in layer_text


@requires_ocr
def test_ocr_does_not_mutate_the_input(client, secret, scanned_text_pdf):
    original = bytes(scanned_text_pdf)
    _ocr(client, secret, scanned_text_pdf)
    assert scanned_text_pdf == original


def test_ocr_is_cached_by_content_hash(tmp_path, monkeypatch, scanned_text_pdf):
    """A second OCR of the same bytes is served from cache without re-running the engine."""
    calls = {"count": 0}

    def fake_searchable(data, language=None, tessdata=None):
        calls["count"] += 1
        out = fitz.open()
        page = out.new_page(width=200, height=100)
        page.insert_text((10, 50), f"cached {OCR_SAMPLE_TEXT}")
        rendered = out.tobytes()
        out.close()
        return rendered

    monkeypatch.setattr(pdf_ocr, "ocr_searchable_pdf", fake_searchable)
    cache_dir = str(tmp_path / "cache")

    first = ocr_pdf(scanned_text_pdf, cache_dir=cache_dir)
    second = ocr_pdf(scanned_text_pdf, cache_dir=cache_dir)

    assert calls["count"] == 1  # the engine ran once; the rest came from cache
    assert first["content_base64"] == second["content_base64"]
    assert "4815" in second["text"]


def test_ocr_reports_unavailable_language(tmp_path, scanned_text_pdf):
    """A missing language file is a clean ValueError (mapped to 422 at the route)."""
    with pytest.raises(ValueError, match="not found"):
        pdf_ocr.ocr_searchable_pdf(scanned_text_pdf, language="zzz", tessdata=str(tmp_path))
