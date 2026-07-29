"""Bake non-destructive overlays onto a copy of a PDF (``POST /pdf/bake``).

Overlays arrive in PDF user space (points, origin bottom-left). PyMuPDF draws in a
top-left, y-down system, so every coordinate is flipped against the (de-rotated) page
height before drawing. The original bytes are never mutated — we draw on an in-memory copy
and return fresh bytes that Laravel persists as a new version (the Golden Rule).

Signatures bake like images (draw/type/upload are all rasterized to PNG client-side), and
``form_field`` overlays flatten a detected AcroForm field's value as text (or an "X" for a
checked box). Unknown types are skipped rather than failing the whole request.
"""

from __future__ import annotations

import base64
import binascii
from itertools import groupby

import fitz
from pydantic import BaseModel, TypeAdapter, ValidationError

from app.schemas.overlays import OverlayIn
from app.services.pdf_document import open_pdf

_OVERLAYS_ADAPTER: TypeAdapter[list[OverlayIn]] = TypeAdapter(list[OverlayIn])

# PyMuPDF Base-14 reserved font codes, keyed by (bold, italic).
_FONTS = {
    (False, False): "helv",
    (True, False): "hebo",
    (False, True): "heit",
    (True, True): "hebi",
}

# Base-14 codes per generic family so edited text can keep the original's serif/mono look
# (the editor detects the source run's family from PDF.js and stores it on the overlay).
_FONT_FAMILIES = {
    "sans": _FONTS,
    "serif": {
        (False, False): "tiro",
        (True, False): "tibo",
        (False, True): "tiit",
        (True, True): "tibi",
    },
    "mono": {
        (False, False): "cour",
        (True, False): "cobo",
        (False, True): "coit",
        (True, True): "cobi",
    },
}

_ALIGN = {"left": 0, "center": 1, "right": 2, "justify": 3}


def parse_overlays(raw: str) -> list[OverlayIn]:
    """Parse + validate the JSON overlay array from the multipart ``overlays`` form field.

    Raises:
        ValueError: when the JSON is malformed or does not match the overlay shape.
    """
    try:
        return _OVERLAYS_ADAPTER.validate_json(raw)
    except ValidationError as exc:
        raise ValueError("Invalid overlays payload.") from exc


def bake_overlays(data: bytes, overlays: list[OverlayIn]) -> dict:
    """Flatten the overlays onto a copy of the PDF and return ``{page_count, content_base64}``.

    Overlays are drawn per page in (z_index, order) sequence so later edits paint on top.

    Raises:
        ValueError: for a corrupt PDF, an out-of-range page, or an invalid overlay payload.
    """
    with open_pdf(data) as doc:
        page_count = doc.page_count
        ordered = sorted(overlays, key=lambda o: (o.page_number, o.z_index, o.order))

        for page_number, group in groupby(ordered, key=lambda o: o.page_number):
            if page_number > page_count:
                raise ValueError(
                    f"Overlay targets page {page_number} but the document has {page_count}."
                )

            page = doc[page_number - 1]
            rotation = page.rotation
            if rotation:
                # Draw in the page's own (unrotated) user space, then restore the flag so the
                # baked page still displays rotated exactly as the editor showed it.
                page.set_rotation(0)

            height = page.rect.height
            for overlay in group:
                _apply(page, height, overlay)

            if rotation:
                page.set_rotation(rotation)

        baked = doc.tobytes(deflate=True, garbage=3)

    return {
        "page_count": page_count,
        "content_base64": base64.b64encode(baked).decode("ascii"),
    }


# --- per-type payload models -------------------------------------------------------------


class _Rect(BaseModel):
    x: float
    y: float
    width: float
    height: float


class _TextPayload(_Rect):
    text: str = ""
    font_size: float = 12.0
    font: str = "sans"  # generic family: sans | serif | mono
    color: str = "#000000"
    bold: bool = False
    italic: bool = False
    align: str = "left"
    opacity: float = 1.0


class _WhiteoutPayload(_Rect):
    color: str = "#ffffff"


class _HighlightPayload(_Rect):
    color: str = "#ffff00"
    opacity: float = 0.4


class _LinePayload(_Rect):
    color: str = "#000000"
    stroke_width: float = 1.5
    opacity: float = 1.0


class _ShapePayload(_Rect):
    kind: str = "rect"  # rect | ellipse | line
    stroke: str = "#000000"
    stroke_width: float = 1.5
    fill: str | None = None
    opacity: float = 1.0


class _FreehandPayload(BaseModel):
    points: list[tuple[float, float]]
    stroke: str = "#000000"
    stroke_width: float = 2.0
    opacity: float = 1.0


class _ImagePayload(_Rect):
    data: str  # raw base64 or a "data:image/...;base64," URL
    opacity: float = 1.0


class _FormFieldPayload(_Rect):
    field_type: str = "text"  # text | checkbox | radio | combobox | listbox | …
    value: str | bool = ""
    font_size: float = 12.0
    color: str = "#000000"
    align: str = "left"
    opacity: float = 1.0


# --- dispatch + handlers -----------------------------------------------------------------


def _apply(page: fitz.Page, height: float, overlay: OverlayIn) -> None:
    """Validate one overlay's payload and draw it, or skip unknown types."""
    handler = _HANDLERS.get(overlay.type)
    if handler is None:
        return  # an unknown type — nothing to bake

    model, draw = handler
    try:
        payload = model(**overlay.payload)
    except ValidationError as exc:
        raise ValueError(f"Invalid '{overlay.type}' overlay payload.") from exc

    draw(page, height, payload)


def _bake_text(page: fitz.Page, height: float, p: _TextPayload) -> None:
    rgb = _color(p.color)
    family = _FONT_FAMILIES.get(p.font, _FONTS)
    page.insert_textbox(
        _rect(p, height),
        p.text,
        fontsize=p.font_size,
        fontname=family[(p.bold, p.italic)],
        color=rgb,
        fill=rgb,
        align=_ALIGN.get(p.align, 0),
        fill_opacity=p.opacity,
        stroke_opacity=p.opacity,
    )


def _bake_whiteout(page: fitz.Page, height: float, p: _WhiteoutPayload) -> None:
    rgb = _color(p.color)
    # color == fill hides the 1pt seam a border would otherwise leave.
    page.draw_rect(_rect(p, height), color=rgb, fill=rgb)


def _bake_highlight(page: fitz.Page, height: float, p: _HighlightPayload) -> None:
    page.draw_rect(_rect(p, height), color=None, fill=_color(p.color), fill_opacity=p.opacity)


def _bake_underline(page: fitz.Page, height: float, p: _LinePayload) -> None:
    _hline(page, height, p, p.y)


def _bake_strike(page: fitz.Page, height: float, p: _LinePayload) -> None:
    _hline(page, height, p, p.y + p.height / 2)


def _bake_shape(page: fitz.Page, height: float, p: _ShapePayload) -> None:
    stroke = _color(p.stroke)
    fill = _color(p.fill) if p.fill else None

    if p.kind == "line":
        page.draw_line(
            _point(p.x, p.y, height),
            _point(p.x + p.width, p.y + p.height, height),
            color=stroke,
            width=p.stroke_width,
            stroke_opacity=p.opacity,
        )
        return

    rect = _rect(p, height)
    draw = page.draw_oval if p.kind == "ellipse" else page.draw_rect
    draw(
        rect,
        color=stroke,
        fill=fill,
        width=p.stroke_width,
        stroke_opacity=p.opacity,
        fill_opacity=p.opacity,
    )


def _bake_freehand(page: fitz.Page, height: float, p: _FreehandPayload) -> None:
    if len(p.points) < 2:
        return

    points = [_point(x, y, height) for x, y in p.points]
    page.draw_polyline(
        points,
        color=_color(p.stroke),
        width=p.stroke_width,
        stroke_opacity=p.opacity,
    )


def _bake_image(page: fitz.Page, height: float, p: _ImagePayload) -> None:
    page.insert_image(_rect(p, height), stream=_decode_image(p.data))


def _bake_form_field(page: fitz.Page, height: float, p: _FormFieldPayload) -> None:
    """Flatten a detected form field's value: text in the box, or an "X" for a checked box.

    Uses ``insert_text`` (baseline placement) rather than ``insert_textbox`` because a form
    field's box is typically just tall enough for its font, and ``insert_textbox`` writes
    nothing at all when a single line overflows by a hair.
    """
    rect = _rect(p, height)
    color = _color(p.color)

    if p.field_type in ("checkbox", "radio"):
        if _is_truthy(p.value):
            size = max(6.0, min(rect.width, rect.height) * 0.85)
            _draw_field_text(page, rect, "X", size, "hebo", color, "center", 1.0, centered=True)
        return

    text = p.value if isinstance(p.value, str) else ""
    if text != "":
        size = max(6.0, min(p.font_size, rect.height))
        _draw_field_text(page, rect, text, size, "helv", color, p.align, p.opacity)


def _draw_field_text(
    page: fitz.Page,
    rect: fitz.Rect,
    text: str,
    size: float,
    fontname: str,
    color: tuple[float, float, float] | None,
    align: str,
    opacity: float,
    centered: bool = False,
) -> None:
    """Place one line of text inside ``rect`` honoring horizontal alignment (PyMuPDF space)."""
    width = fitz.get_text_length(text, fontname=fontname, fontsize=size)

    if align == "center" or centered:
        x = rect.x0 + (rect.width - width) / 2
    elif align == "right":
        x = rect.x1 - width - 2
    else:
        x = rect.x0 + 2

    # Vertically center the "X"; sit field text on the box's baseline with descender room.
    baseline = (
        rect.y0 + (rect.height + size * 0.7) / 2 if centered else rect.y1 - max(1.5, size * 0.25)
    )

    page.insert_text(
        fitz.Point(x, baseline),
        text,
        fontsize=size,
        fontname=fontname,
        color=color,
        fill_opacity=opacity,
        stroke_opacity=opacity,
    )


def _is_truthy(value: str | bool) -> bool:
    """Whether a checkbox/radio value means "checked"."""
    if isinstance(value, bool):
        return value

    return value.strip().lower() in {"true", "yes", "on", "1", "x", "checked"}


_HANDLERS = {
    "text": (_TextPayload, _bake_text),
    "whiteout": (_WhiteoutPayload, _bake_whiteout),
    "highlight": (_HighlightPayload, _bake_highlight),
    "underline": (_LinePayload, _bake_underline),
    "strike": (_LinePayload, _bake_strike),
    "shape": (_ShapePayload, _bake_shape),
    "freehand": (_FreehandPayload, _bake_freehand),
    "image": (_ImagePayload, _bake_image),
    "signature": (_ImagePayload, _bake_image),
    "form_field": (_FormFieldPayload, _bake_form_field),
}


# --- geometry + color helpers ------------------------------------------------------------


def _rect(p: _Rect, height: float) -> fitz.Rect:
    """Convert a bottom-left PDF-space rectangle to a top-left PyMuPDF rectangle."""
    return fitz.Rect(p.x, height - (p.y + p.height), p.x + p.width, height - p.y)


def _point(x: float, y: float, height: float) -> fitz.Point:
    """Flip a bottom-left PDF-space point to PyMuPDF's top-left space."""
    return fitz.Point(x, height - y)


def _hline(page: fitz.Page, height: float, p: _LinePayload, y: float) -> None:
    """Draw a horizontal line spanning the payload rectangle at PDF-space height ``y``."""
    page.draw_line(
        _point(p.x, y, height),
        _point(p.x + p.width, y, height),
        color=_color(p.color),
        width=p.stroke_width,
        stroke_opacity=p.opacity,
    )


def _color(value: str | None) -> tuple[float, float, float] | None:
    """Parse a ``#rgb`` / ``#rrggbb`` hex string into a 0–1 RGB tuple (``None`` passes through)."""
    if value is None:
        return None

    digits = value.strip().lstrip("#")
    if len(digits) == 3:
        digits = "".join(c * 2 for c in digits)
    if len(digits) != 6:
        raise ValueError(f"Invalid color: {value!r}")

    try:
        r, g, b = (int(digits[i : i + 2], 16) / 255 for i in (0, 2, 4))
    except ValueError as exc:
        raise ValueError(f"Invalid color: {value!r}") from exc

    return (r, g, b)


def _decode_image(data: str) -> bytes:
    """Decode an image overlay's base64 string (with or without a data-URL prefix)."""
    if data.startswith("data:"):
        _, _, data = data.partition(",")

    try:
        return base64.b64decode(data, validate=True)
    except (binascii.Error, ValueError) as exc:
        raise ValueError("Invalid image data.") from exc
