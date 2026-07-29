"""Smart PDF → DOCX export (``POST /pdf/export/docx``) — the headline feature.

We classify the PDF (native / scanned / mixed) and route accordingly:

    native           → pdf2docx (layout-aware reconstruction)
    scanned / mixed  → OCR first, then emit the recognized text as an editable .docx

OCR-first is the step naive converters skip — pdf2docx itself refuses scanned input ("Words
count: 0 … not supported"), so a tool that only runs pdf2docx returns an empty/garbled file for
a scanned document. We instead recover the text with OCR and lay it out as paragraphs. The
result is editable and *close*, not a pixel-perfect clone. The input bytes are never mutated;
the ``.docx`` is a brand-new artifact (the Golden Rule).
"""

from __future__ import annotations

import base64
import io
import logging
import os
import re
import tempfile

from docx import Document as DocxDocument
from docx.oxml.ns import qn
from pdf2docx import Converter

from app.services.pdf_document import open_pdf
from app.services.pdf_info import analyze_pdf
from app.services.pdf_ocr import ocr_pdf

_WORD_RE = re.compile(r"[^\W_]+", re.UNICODE)
# Single-character tokens are ignored when deciding a line was dropped — they are noisy
# (stray punctuation artifacts, list bullets) and tokenize inconsistently across converters.
_MIN_RECOVER_WORD_LEN = 2

# pdf2docx logs each converted page at INFO on the root logger; quiet it to keep output clean.
logging.getLogger("pdf2docx").setLevel(logging.ERROR)

# source_types with at least one image-only page, which therefore benefit from OCR first.
_NEEDS_OCR = {"scanned", "mixed"}


def export_docx(
    data: bytes,
    language: str | None = None,
    tessdata: str | None = None,
    cache_dir: str | None = None,
) -> dict:
    """Convert a PDF to DOCX, OCR-first when the source is scanned.

    Returns ``{source_type, ocr_applied, page_count, content_base64}`` where ``content_base64``
    is the produced ``.docx``.

    Raises:
        ValueError: for unreadable PDFs, or when a scanned source needs OCR that is unavailable.
    """
    source_type = analyze_pdf(data)["source_type"]

    if source_type in _NEEDS_OCR:
        ocr = ocr_pdf(data, language=language, tessdata=tessdata, cache_dir=cache_dir)
        searchable = base64.b64decode(ocr["content_base64"])
        docx_bytes, page_count = _searchable_to_docx(searchable)
        ocr_applied = True
    else:
        docx_bytes, page_count = _native_to_docx(data)
        ocr_applied = False

    return {
        "source_type": source_type,
        "ocr_applied": ocr_applied,
        "page_count": page_count,
        "content_base64": base64.b64encode(docx_bytes).decode("ascii"),
    }


def _native_to_docx(pdf_bytes: bytes) -> tuple[bytes, int]:
    """Convert a native (text) PDF with pdf2docx; return ``(docx_bytes, page_count)``.

    pdf2docx reads and writes file paths, so the bytes are staged in a temporary directory
    that is removed once the ``.docx`` has been read back into memory.
    """
    with open_pdf(pdf_bytes) as doc:
        page_count = doc.page_count

    with tempfile.TemporaryDirectory(prefix="pdf2docx-") as workdir:
        pdf_path = os.path.join(workdir, "input.pdf")
        docx_path = os.path.join(workdir, "output.docx")
        with open(pdf_path, "wb") as handle:
            handle.write(pdf_bytes)

        converter = Converter(pdf_path)
        try:
            converter.convert(docx_path)
        finally:
            converter.close()

        with open(docx_path, "rb") as handle:
            docx_bytes = handle.read()

    # pdf2docx is layout-aware but can silently drop text it cannot place (e.g. runs over a
    # watermark/image). Recover any such text so the export never loses content.
    docx_bytes = _recover_dropped_text(pdf_bytes, docx_bytes)

    return docx_bytes, page_count


def _recover_dropped_text(pdf_bytes: bytes, docx_bytes: bytes) -> bytes:
    """Append any source text missing from the converted DOCX, page by page.

    Compares the words pdf2docx emitted against the PDF's own text. Any source line that
    contains a word missing from the DOCX is appended verbatim under a clearly labelled
    "Recovered text" section, so even a *few* dropped words (not just whole dropped lines) are
    never silently lost — pdf2docx tends to drop runs that overlap a watermark/logo. The line is
    kept whole for readable context. Returns the DOCX unchanged when nothing is missing.
    """
    document = DocxDocument(io.BytesIO(docx_bytes))
    present = _docx_words(document)

    recovered: list[tuple[int, list[str]]] = []
    with open_pdf(pdf_bytes) as doc:
        for index, page in enumerate(doc):
            missing = [line for line in _page_lines(page) if _line_is_missing(line, present)]
            if missing:
                recovered.append((index + 1, missing))

    if not recovered:
        return docx_bytes

    document.add_page_break()
    document.add_heading("Recovered text", level=2)
    document.add_paragraph(
        "Text below was present in the original PDF but could not be placed in the layout above."
    )
    for page_number, lines in recovered:
        document.add_heading(f"Page {page_number}", level=3)
        for line in lines:
            document.add_paragraph(line)

    buffer = io.BytesIO()
    document.save(buffer)

    return buffer.getvalue()


def _docx_words(document: DocxDocument) -> set[str]:
    """Every word in a DOCX, across body, tables and text boxes (all ``w:t`` runs)."""
    words: set[str] = set()
    for node in document.element.iter(qn("w:t")):
        if node.text:
            words.update(_WORD_RE.findall(node.text.lower()))

    return words


def _page_lines(page) -> list[str]:
    """Non-trivial text lines on a page (skips blank and single-character lines)."""
    lines = []
    for raw in page.get_text("text").splitlines():
        line = raw.strip()
        if len(line) > 1:
            lines.append(line)

    return lines


def _line_is_missing(line: str, present: set[str]) -> bool:
    """True when ``line`` has any non-trivial word absent from ``present`` (so it was dropped)."""
    words = _WORD_RE.findall(line.lower())

    return any(len(word) >= _MIN_RECOVER_WORD_LEN and word not in present for word in words)


def _searchable_to_docx(searchable_pdf: bytes) -> tuple[bytes, int]:
    """Build a text ``.docx`` from a searchable (OCR'd) PDF; return ``(docx_bytes, page_count)``.

    pdf2docx cannot lay out a scanned page, so for the OCR path we take the recognized text
    blocks (in reading order) and emit them as paragraphs, one page per page break. This is
    text-faithful and editable — the layout fidelity of a scanned source is inherently limited.
    """
    document = DocxDocument()

    with open_pdf(searchable_pdf) as doc:
        page_count = doc.page_count
        for index, page in enumerate(doc):
            if index > 0:
                document.add_page_break()
            for block in _text_blocks_in_reading_order(page):
                document.add_paragraph(block)

    buffer = io.BytesIO()
    document.save(buffer)

    return buffer.getvalue(), page_count


def _text_blocks_in_reading_order(page) -> list[str]:
    """The page's non-empty text blocks, ordered top-to-bottom then left-to-right."""
    blocks = page.get_text("blocks")  # (x0, y0, x1, y1, text, block_no, block_type)
    ordered = sorted(blocks, key=lambda block: (round(block[1], 1), block[0]))

    return [block[4].strip() for block in ordered if block[4] and block[4].strip()]
