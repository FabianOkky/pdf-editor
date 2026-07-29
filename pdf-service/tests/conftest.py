"""Shared pytest fixtures.

The shared secret is set in the environment **before** importing the app so the cached
settings pick it up. Tests then exercise the auth dependency with this known value.
"""

from __future__ import annotations

import os
import tempfile

import fitz
import pytest
from fastapi.testclient import TestClient

TEST_SECRET = "test-secret"
os.environ["PDF_SERVICE_SECRET"] = TEST_SECRET
# Keep the OCR cache out of the repo during tests: point it at a throwaway temp directory
# (set before the app/settings are imported so the cached settings pick it up).
os.environ["PDF_OCR_CACHE_DIR"] = tempfile.mkdtemp(prefix="pdf-ocr-cache-test-")
# Pin the AI provider/model so tests are hermetic — independent of any local pdf-service/.env
# (a developer running Ollama/Gemini locally must not change what the suite asserts).
os.environ["AI_PROVIDER"] = "anthropic"
os.environ["AI_MODEL"] = "claude-opus-4-8"
os.environ["AI_EMBEDDING_PROVIDER"] = "hash"

from app.main import app  # noqa: E402  (must import after the secret env is set)


@pytest.fixture
def secret() -> str:
    """The shared secret configured for the test app."""
    return TEST_SECRET


@pytest.fixture
def client() -> TestClient:
    """A FastAPI test client bound to the app."""
    return TestClient(app)


# US Letter in PDF points, set explicitly so tests don't depend on PyMuPDF's default size.
_LETTER_WIDTH = 612
_LETTER_HEIGHT = 792


def _text_page(doc: fitz.Document) -> None:
    """Append a US Letter page with real, extractable text (a "native" page)."""
    page = doc.new_page(width=_LETTER_WIDTH, height=_LETTER_HEIGHT)
    page.insert_text((72, 72), "Hello world. This page has real extractable text content.")


def _image_page(doc: fitz.Document) -> None:
    """Append a US Letter page with only a raster image and no text (a "scanned" page)."""
    page = doc.new_page(width=_LETTER_WIDTH, height=_LETTER_HEIGHT)
    pixmap = fitz.Pixmap(fitz.csRGB, fitz.IRect(0, 0, 300, 400))
    pixmap.clear_with(200)  # fill with a flat light-gray color
    page.insert_image(page.rect, pixmap=pixmap)


def _build_pdf(*builders) -> bytes:
    """Build a PDF in-memory from the given per-page builder callables."""
    doc = fitz.open()
    for builder in builders:
        builder(doc)
    data = doc.tobytes()
    doc.close()

    return data


@pytest.fixture
def native_pdf() -> bytes:
    """A 2-page PDF whose pages both contain real text."""
    return _build_pdf(_text_page, _text_page)


@pytest.fixture
def scanned_pdf() -> bytes:
    """A 1-page image-only PDF (no extractable text)."""
    return _build_pdf(_image_page)


@pytest.fixture
def mixed_pdf() -> bytes:
    """A 2-page PDF: one text page and one image-only page."""
    return _build_pdf(_text_page, _image_page)


# A short, distinctive phrase rendered into the scanned fixture so OCR assertions can check
# that the recognized text actually came back (and not just that *some* text did).
OCR_SAMPLE_TEXT = "Invoice Number 4815 Total Amount 162342"


@pytest.fixture
def scanned_text_pdf() -> bytes:
    """A 1-page image-only PDF whose picture shows :data:`OCR_SAMPLE_TEXT`.

    The page carries no extractable text (it is a flattened raster), so a naive converter would
    return nothing — but OCR can recover the words, which is exactly what the export pipeline
    must prove. Built by rendering real text, then re-inserting that render as a flat image.
    """
    typeset = fitz.open()
    page = typeset.new_page(width=_LETTER_WIDTH, height=_LETTER_HEIGHT)
    page.insert_text((72, 144), OCR_SAMPLE_TEXT, fontsize=28)
    pixmap = page.get_pixmap(dpi=200)
    typeset.close()

    scanned = fitz.open()
    image_page = scanned.new_page(width=_LETTER_WIDTH, height=_LETTER_HEIGHT)
    image_page.insert_image(image_page.rect, pixmap=pixmap)
    data = scanned.tobytes()
    scanned.close()

    return data


def _add_widget(page: fitz.Document, name: str, field_type: int, rect: fitz.Rect, **kwargs) -> None:
    """Add one AcroForm widget to a page (helper for the form fixture)."""
    widget = fitz.Widget()
    widget.field_name = name
    widget.field_type = field_type
    widget.rect = rect
    for key, value in kwargs.items():
        setattr(widget, key, value)
    page.add_widget(widget)


@pytest.fixture
def form_pdf() -> bytes:
    """A 1-page US Letter PDF carrying a text, checkbox, combobox, and read-only field."""
    doc = fitz.open()
    page = doc.new_page(width=_LETTER_WIDTH, height=_LETTER_HEIGHT)
    _add_widget(
        page, "full_name", fitz.PDF_WIDGET_TYPE_TEXT, fitz.Rect(100, 100, 300, 120), field_value=""
    )
    _add_widget(
        page,
        "agree",
        fitz.PDF_WIDGET_TYPE_CHECKBOX,
        fitz.Rect(100, 150, 115, 165),
        field_value=False,
    )
    _add_widget(
        page,
        "color",
        fitz.PDF_WIDGET_TYPE_COMBOBOX,
        fitz.Rect(100, 200, 300, 220),
        choice_values=["Red", "Green", "Blue"],
        field_value="Red",
    )
    _add_widget(
        page,
        "ref_no",
        fitz.PDF_WIDGET_TYPE_TEXT,
        fitz.Rect(100, 250, 300, 270),
        field_value="LOCKED",
        field_flags=1,  # ReadOnly
    )
    data = doc.tobytes()
    doc.close()

    return data
