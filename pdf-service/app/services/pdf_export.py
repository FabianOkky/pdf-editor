"""Smart PDF → DOCX export (``POST /pdf/export/docx``) — the headline feature.

We classify the PDF (native / scanned / mixed) and route accordingly:

    native   → pdf2docx (layout-aware reconstruction), then recover any dropped text
    mixed    → pdf2docx for the whole file (native pages lay out properly), then OCR *only*
               the image-only pages and fold their text in
    scanned  → OCR first, then rebuild the layout from the recognized spans

OCR-first is the step naive converters skip — pdf2docx itself refuses scanned input ("Words
count: 0 … not supported"), so a tool that only runs pdf2docx returns an empty/garbled file for
a scanned document. We instead recover the text with OCR and rebuild a laid-out document from
it: real page geometry, per-span font sizes and weights, detected alignment and column order.
The result is editable and *close*, not a pixel-perfect clone. The input bytes are never
mutated; the ``.docx`` is a brand-new artifact (the Golden Rule).
"""

from __future__ import annotations

import base64
import io
import logging
import os
import re
import statistics
import tempfile
import unicodedata

import fitz
from docx import Document as DocxDocument
from docx.enum.section import WD_ORIENT
from docx.enum.text import WD_ALIGN_PARAGRAPH
from docx.oxml.ns import qn
from docx.shared import Pt
from pdf2docx import Converter

from app.services.pdf_document import open_pdf
from app.services.pdf_info import TEXT_CHAR_THRESHOLD, analyze_pdf
from app.services.pdf_ocr import ocr_pdf

logger = logging.getLogger(__name__)

_WORD_RE = re.compile(r"[^\W_]+", re.UNICODE)
# Single-character tokens are ignored when deciding a line was dropped — they are noisy
# (stray punctuation artifacts, list bullets) and tokenize inconsistently across converters.
_MIN_RECOVER_WORD_LEN = 2

# Typographic ligatures PDF fonts emit as single glyphs; Word writes them as plain letters, so
# they must be folded before comparing words or every "office"/"final" looks like a dropped word.
_LIGATURES = {
    "ﬀ": "ff",
    "ﬁ": "fi",
    "ﬂ": "fl",
    "ﬃ": "ffi",
    "ﬄ": "ffl",
    "ﬅ": "st",
    "ﬆ": "st",
    "Ĳ": "ij",
    "ĳ": "ij",
    "Œ": "oe",
    "œ": "oe",
    "Æ": "ae",
    "æ": "ae",
}

# pdf2docx logs each converted page at INFO on the root logger; quiet it to keep output clean.
logging.getLogger("pdf2docx").setLevel(logging.ERROR)

# Fallback page geometry (US Letter, in points) when a page's own size cannot be read.
_DEFAULT_PAGE = (612.0, 792.0)
# Body text is assumed when a span is no larger than this multiple of the document's median
# size; anything above reads as a heading and is emitted bold.
_HEADING_RATIO = 1.22
# Content is treated as centered when its midpoint sits this close (as a fraction of the text
# column width) to the column's midpoint.
_CENTER_TOLERANCE = 0.06
# Two blocks belong to the same visual row when their tops are within this many points.
_ROW_BAND = 6.0


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

    if source_type == "scanned":
        ocr = ocr_pdf(data, language=language, tessdata=tessdata, cache_dir=cache_dir)
        searchable = base64.b64decode(ocr["content_base64"])
        docx_bytes, page_count = _searchable_to_docx(searchable)
        ocr_applied = True
    elif source_type == "mixed":
        docx_bytes, page_count, ocr_applied = _mixed_to_docx(
            data, language=language, tessdata=tessdata, cache_dir=cache_dir
        )
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
    that is removed once the ``.docx`` has been read back into memory. If pdf2docx fails
    outright we still return a usable document by rebuilding it from the PDF's own text
    spans — an export must never come back empty.
    """
    with open_pdf(pdf_bytes) as doc:
        page_count = doc.page_count

    docx_bytes = _run_pdf2docx(pdf_bytes)

    if docx_bytes is None:
        return _layout_to_docx(pdf_bytes)[0], page_count

    # pdf2docx is layout-aware but can silently drop text it cannot place (e.g. runs over a
    # watermark/image). Recover any such text so the export never loses content.
    docx_bytes = _recover_dropped_text(pdf_bytes, docx_bytes)

    return docx_bytes, page_count


def _mixed_to_docx(
    pdf_bytes: bytes,
    language: str | None = None,
    tessdata: str | None = None,
    cache_dir: str | None = None,
) -> tuple[bytes, int, bool]:
    """Convert a mixed PDF: layout-aware for native pages, OCR for the image-only ones.

    Running the whole file through OCR (the previous behaviour) threw away the layout of every
    native page. Instead pdf2docx converts the document as-is — which handles the native pages
    properly — and OCR is applied only to the pages that carry no extractable text, whose
    recognized text is then folded in by the same recovery pass. When OCR is unavailable the
    native part still converts, so the export degrades gracefully instead of failing.

    Returns ``(docx_bytes, page_count, ocr_applied)``.
    """
    with open_pdf(pdf_bytes) as doc:
        page_count = doc.page_count
        image_pages = [index for index, page in enumerate(doc) if not _has_text(page)]

    docx_bytes = _run_pdf2docx(pdf_bytes)

    if docx_bytes is None:
        docx_bytes = _layout_to_docx(pdf_bytes)[0]

    ocr_text_by_page: dict[int, list[str]] = {}
    ocr_applied = False

    if image_pages:
        try:
            ocr = ocr_pdf(
                _subset(pdf_bytes, image_pages),
                language=language,
                tessdata=tessdata,
                cache_dir=cache_dir,
            )
        except ValueError as exc:  # OCR unavailable — keep the native conversion.
            logger.warning("Skipping OCR for the scanned pages of a mixed PDF: %s", exc)
        else:
            ocr_applied = True
            searchable = base64.b64decode(ocr["content_base64"])
            with open_pdf(searchable) as ocr_doc:
                for offset, page in enumerate(ocr_doc):
                    if offset >= len(image_pages):
                        break
                    lines = _page_lines(page)
                    if lines:
                        ocr_text_by_page[image_pages[offset] + 1] = lines

    docx_bytes = _recover_dropped_text(pdf_bytes, docx_bytes, extra_lines=ocr_text_by_page)

    return docx_bytes, page_count, ocr_applied


def _run_pdf2docx(pdf_bytes: bytes) -> bytes | None:
    """Convert with pdf2docx, returning the ``.docx`` bytes or ``None`` when it fails.

    pdf2docx raises on inputs it cannot handle (e.g. a page with no placeable words); callers
    fall back to rebuilding the document from the PDF's text spans.
    """
    with tempfile.TemporaryDirectory(prefix="pdf2docx-") as workdir:
        pdf_path = os.path.join(workdir, "input.pdf")
        docx_path = os.path.join(workdir, "output.docx")
        with open(pdf_path, "wb") as handle:
            handle.write(pdf_bytes)

        converter = None
        try:
            converter = Converter(pdf_path)
            converter.convert(docx_path)
        except Exception as exc:  # noqa: BLE001 — any converter failure falls back below.
            logger.warning("pdf2docx conversion failed, falling back to text layout: %s", exc)
            return None
        finally:
            if converter is not None:
                converter.close()

        with open(docx_path, "rb") as handle:
            return handle.read()


def _subset(pdf_bytes: bytes, page_indexes: list[int]) -> bytes:
    """A new PDF containing only ``page_indexes`` (0-based), in order. Input is not mutated."""
    with open_pdf(pdf_bytes) as doc:
        out = fitz.open()
        try:
            for index in page_indexes:
                out.insert_pdf(doc, from_page=index, to_page=index)
            return out.tobytes(deflate=True, garbage=3)
        finally:
            out.close()


def _recover_dropped_text(
    pdf_bytes: bytes,
    docx_bytes: bytes,
    extra_lines: dict[int, list[str]] | None = None,
) -> bytes:
    """Append any source text missing from the converted DOCX, page by page.

    Compares the words pdf2docx emitted against the PDF's own text (plus any ``extra_lines``
    recovered by OCR for image-only pages, keyed by 1-based page number). Any source line that
    contains a word missing from the DOCX is appended verbatim under a clearly labelled
    "Recovered text" section, so even a *few* dropped words (not just whole dropped lines) are
    never silently lost — pdf2docx tends to drop runs that overlap a watermark/logo.

    Words are normalized (ligatures folded, accents stripped, lowercased) before comparison, so
    a PDF's "oﬃce" ligature is not mistaken for text Word failed to write. Returns the DOCX
    unchanged when nothing is missing.
    """
    document = DocxDocument(io.BytesIO(docx_bytes))
    present = _docx_words(document)

    by_page: dict[int, list[str]] = {}
    with open_pdf(pdf_bytes) as doc:
        for index, page in enumerate(doc):
            by_page[index + 1] = _page_lines(page)

    for page_number, lines in (extra_lines or {}).items():
        by_page.setdefault(page_number, [])
        by_page[page_number].extend(lines)

    recovered: list[tuple[int, list[str]]] = []
    for page_number in sorted(by_page):
        seen: set[str] = set()
        missing = []
        for line in by_page[page_number]:
            if line in seen or not _line_is_missing(line, present):
                continue
            seen.add(line)
            missing.append(line)
        if missing:
            recovered.append((page_number, missing))

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
            words.update(_words(node.text))

    return words


def _words(text: str) -> list[str]:
    """Normalized word tokens of ``text`` (ligatures folded, accents stripped, lowercased)."""
    return _WORD_RE.findall(_normalize(text))


def _normalize(text: str) -> str:
    """Fold ligatures, strip combining accents and lowercase, for robust word comparison."""
    for glyph, replacement in _LIGATURES.items():
        text = text.replace(glyph, replacement)

    decomposed = unicodedata.normalize("NFKD", text)

    return "".join(char for char in decomposed if not unicodedata.combining(char)).lower()


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
    words = _words(line)

    return any(len(word) >= _MIN_RECOVER_WORD_LEN and word not in present for word in words)


def _has_text(page) -> bool:
    """Whether a page carries enough extractable text to be treated as native (not scanned)."""
    return sum(1 for char in page.get_text("text") if not char.isspace()) >= TEXT_CHAR_THRESHOLD


def _searchable_to_docx(searchable_pdf: bytes) -> tuple[bytes, int]:
    """Build a laid-out ``.docx`` from a searchable (OCR'd) PDF; return ``(docx_bytes, pages)``.

    pdf2docx cannot lay out a scanned page, so for the OCR path we rebuild the document from
    the recognized text spans ourselves — see :func:`_layout_to_docx`.
    """
    return _layout_to_docx(searchable_pdf)


def _layout_to_docx(pdf_bytes: bytes) -> tuple[bytes, int]:
    """Rebuild an editable ``.docx`` from a PDF's text spans; return ``(docx_bytes, pages)``.

    This is the fallback/OCR renderer. Unlike a naive "dump every block as a paragraph" it
    carries over what actually makes a Word document readable: the real page size and
    orientation, margins taken from where the text sits, per-span font size and bold/italic,
    detected paragraph alignment, headings (spans notably larger than the body), and a
    column-aware reading order so two-column pages do not interleave.
    """
    document = DocxDocument()

    with open_pdf(pdf_bytes) as doc:
        page_count = doc.page_count
        pages = [_page_layout(page) for page in doc]

    _apply_page_geometry(document, pages)
    body_size = _body_font_size(pages)

    for index, page in enumerate(pages):
        if index > 0:
            document.add_page_break()
        for block in page["blocks"]:
            _write_block(document, block, page, body_size)

    buffer = io.BytesIO()
    document.save(buffer)

    return buffer.getvalue(), page_count


def _page_layout(page) -> dict:
    """Extract a page's text blocks with geometry and styling, in reading order.

    Returns ``{width, height, left, right, blocks}`` where each block is
    ``{bbox, lines, size, bold, italic}`` and ``lines`` are the block's text lines.
    """
    width = round(page.rect.width, 2) or _DEFAULT_PAGE[0]
    height = round(page.rect.height, 2) or _DEFAULT_PAGE[1]

    blocks = []
    for raw in page.get_text("dict").get("blocks", []):
        if raw.get("type") != 0:  # 0 = text; images carry no recoverable words.
            continue
        block = _text_block(raw)
        if block is not None:
            blocks.append(block)

    left = min((block["bbox"][0] for block in blocks), default=72.0)
    right = max((block["bbox"][2] for block in blocks), default=width - 72.0)

    return {
        "width": width,
        "height": height,
        "left": left,
        "right": right,
        "blocks": _reading_order(blocks, width),
    }


def _text_block(raw: dict) -> dict | None:
    """Fold a PyMuPDF text block into ``{bbox, lines, size, bold, italic}``; ``None`` if empty."""
    lines: list[str] = []
    sizes: list[tuple[float, int]] = []
    bold_chars = 0
    italic_chars = 0
    total_chars = 0

    for line in raw.get("lines", []):
        text = "".join(span.get("text", "") for span in line.get("spans", []))
        if text.strip():
            lines.append(text.strip())
        for span in line.get("spans", []):
            length = len(span.get("text", "").strip())
            if not length:
                continue
            total_chars += length
            sizes.append((float(span.get("size", 0) or 0), length))
            font = str(span.get("font", "")).lower()
            flags = int(span.get("flags", 0) or 0)
            if "bold" in font or "black" in font or flags & 2**4:
                bold_chars += length
            if "italic" in font or "oblique" in font or flags & 2**1:
                italic_chars += length

    if not lines or not total_chars:
        return None

    return {
        "bbox": tuple(round(value, 2) for value in raw.get("bbox", (0, 0, 0, 0))),
        "lines": lines,
        "size": _dominant_size(sizes),
        "bold": bold_chars * 2 > total_chars,
        "italic": italic_chars * 2 > total_chars,
    }


def _dominant_size(sizes: list[tuple[float, int]]) -> float:
    """The character-count-weighted median font size of a block's spans."""
    weighted = [size for size, length in sizes for _ in range(min(length, 200)) if size > 0]

    return round(statistics.median(weighted), 1) if weighted else 11.0


def _reading_order(blocks: list[dict], page_width: float) -> list[dict]:
    """Order blocks the way a human reads them, keeping two-column pages un-interleaved."""
    if not blocks:
        return []

    columns = _split_columns(blocks, page_width)

    ordered: list[dict] = []
    for column in columns:
        ordered.extend(
            sorted(
                column, key=lambda block: (round(block["bbox"][1] / _ROW_BAND), block["bbox"][0])
            )
        )

    return ordered


def _split_columns(blocks: list[dict], page_width: float) -> list[list[dict]]:
    """Split blocks into left/right columns when the page clearly has a gutter, else one column.

    A page is two-column when every block sits entirely on one side of the page midline and
    both sides hold a meaningful share of the content — the cheap test that catches real
    two-column layouts without misfiring on a wide table or a centered title.
    """
    midline = page_width / 2
    left = [block for block in blocks if block["bbox"][2] <= midline]
    right = [block for block in blocks if block["bbox"][0] >= midline]

    spans_gutter = len(left) + len(right) < len(blocks)
    lopsided = min(len(left), len(right)) < max(2, len(blocks) // 5)

    if spans_gutter or lopsided:
        return [blocks]

    return [left, right]


def _apply_page_geometry(document: DocxDocument, pages: list[dict]) -> None:
    """Size the document's section from the PDF's own pages (size, orientation, margins)."""
    if not pages:
        return

    width = statistics.median([page["width"] for page in pages])
    height = statistics.median([page["height"] for page in pages])
    left = statistics.median([page["left"] for page in pages])
    right = statistics.median([page["right"] for page in pages])

    section = document.sections[0]
    section.orientation = WD_ORIENT.LANDSCAPE if width > height else WD_ORIENT.PORTRAIT
    section.page_width = Pt(width)
    section.page_height = Pt(height)
    section.left_margin = Pt(_clamp(left, 18, width / 3))
    section.right_margin = Pt(_clamp(width - right, 18, width / 3))
    section.top_margin = Pt(36)
    section.bottom_margin = Pt(36)


def _body_font_size(pages: list[dict]) -> float:
    """The document's median block font size — the baseline headings are measured against."""
    sizes = [block["size"] for page in pages for block in page["blocks"] if block["size"] > 0]

    return round(statistics.median(sizes), 1) if sizes else 11.0


def _write_block(document: DocxDocument, block: dict, page: dict, body_size: float) -> None:
    """Emit one PDF text block as a Word paragraph, carrying size, weight and alignment over."""
    paragraph = document.add_paragraph()
    paragraph.alignment = _alignment(block, page)
    paragraph.paragraph_format.space_after = Pt(4)

    is_heading = block["size"] >= body_size * _HEADING_RATIO

    for index, line in enumerate(block["lines"]):
        run = paragraph.add_run(line)
        run.font.size = Pt(_clamp(block["size"], 6, 72))
        run.bold = block["bold"] or is_heading
        run.italic = block["italic"]
        if index < len(block["lines"]) - 1:
            run.add_break()


def _alignment(block: dict, page: dict):
    """Guess a paragraph's alignment from where its box sits inside the text column."""
    left, right = page["left"], page["right"]
    column = right - left

    if column <= 0:
        return WD_ALIGN_PARAGRAPH.LEFT

    x0, _, x1, _ = block["bbox"][0], block["bbox"][1], block["bbox"][2], block["bbox"][3]
    indented = (x0 - left) > column * 0.1

    if indented and abs(((x0 + x1) / 2) - ((left + right) / 2)) < column * _CENTER_TOLERANCE:
        return WD_ALIGN_PARAGRAPH.CENTER

    if indented and (right - x1) < column * 0.02:
        return WD_ALIGN_PARAGRAPH.RIGHT

    return WD_ALIGN_PARAGRAPH.LEFT


def _clamp(value: float, low: float, high: float) -> float:
    """``value`` constrained to the inclusive ``[low, high]`` range."""
    return max(low, min(high, value))
