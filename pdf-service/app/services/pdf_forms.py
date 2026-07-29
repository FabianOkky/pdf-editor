"""AcroForm detection and filling (``POST /pdf/form-fields`` and ``/form-fields/fill``).

Detection reads a PDF's interactive widgets and returns them in PDF user space (bottom-left
origin) so the editor positions inputs the same way it positions overlays. Filling sets
widget values with PyMuPDF on an **in-memory copy** of the original — the upload on disk is
never mutated (the Golden Rule). An optional flatten bakes the filled appearances into static
page content (``Document.bake(widgets=True)``) and drops the interactive widgets.
"""

from __future__ import annotations

import base64
import json

import fitz

from app.services.pdf_document import open_pdf

# PyMuPDF widget-type constant → the normalized type string the editor understands.
_FIELD_TYPES = {
    fitz.PDF_WIDGET_TYPE_UNKNOWN: "unknown",
    fitz.PDF_WIDGET_TYPE_BUTTON: "button",
    fitz.PDF_WIDGET_TYPE_CHECKBOX: "checkbox",
    fitz.PDF_WIDGET_TYPE_COMBOBOX: "combobox",
    fitz.PDF_WIDGET_TYPE_LISTBOX: "listbox",
    fitz.PDF_WIDGET_TYPE_RADIOBUTTON: "radio",
    fitz.PDF_WIDGET_TYPE_SIGNATURE: "signature",
    fitz.PDF_WIDGET_TYPE_TEXT: "text",
}

_CHECKBOX_TYPES = {"checkbox", "radio"}

# AcroForm field flag bits (PDF spec, table 226): ReadOnly = 1, Required = 2.
_FLAG_READONLY = 1
_FLAG_REQUIRED = 2

_TRUTHY = {"true", "yes", "on", "1", "x", "checked"}


def list_form_fields(data: bytes) -> dict:
    """Return ``{is_form, fields}`` for a PDF's interactive AcroForm widgets.

    Each field's rectangle is converted from PyMuPDF's top-left space to PDF user space
    (bottom-left origin) so the editor can place an input over it directly.

    Raises:
        ValueError: when the bytes are not a readable PDF.
    """
    with open_pdf(data) as doc:
        is_form = bool(doc.is_form_pdf)
        fields: list[dict] = []

        for index in range(doc.page_count):
            page = doc[index]
            height = page.rect.height
            for widget in page.widgets():
                rect = widget.rect
                fields.append(
                    {
                        "name": widget.field_name or "",
                        "type": _FIELD_TYPES.get(widget.field_type, "unknown"),
                        "value": _value_to_str(widget.field_value),
                        "page_number": index + 1,
                        "x": rect.x0,
                        "y": height - rect.y1,
                        "width": rect.width,
                        "height": rect.height,
                        "options": list(widget.choice_values or []),
                        "readonly": bool((widget.field_flags or 0) & _FLAG_READONLY),
                        "required": bool((widget.field_flags or 0) & _FLAG_REQUIRED),
                    }
                )

    return {"is_form": is_form, "fields": fields}


def parse_fill_values(raw: str) -> dict[str, object]:
    """Parse the ``values`` form field (a JSON object mapping field name → value).

    Raises:
        ValueError: when the JSON is malformed or is not a JSON object.
    """
    try:
        parsed = json.loads(raw)
    except json.JSONDecodeError as exc:
        raise ValueError("Invalid form values payload.") from exc

    if not isinstance(parsed, dict):
        raise ValueError("Form values must be a JSON object of field name → value.")

    return parsed


def fill_form_fields(data: bytes, values: dict[str, object], flatten: bool = False) -> dict:
    """Set the given field values on a copy of the PDF and return ``{page_count, content_base64}``.

    Read-only fields and names absent from ``values`` are left untouched. When ``flatten`` is
    set, the filled appearances are baked into the page content and the widgets removed so the
    result is no longer an interactive form. The original bytes are never modified.

    Raises:
        ValueError: when the bytes are not a readable PDF.
    """
    with open_pdf(data) as doc:
        for index in range(doc.page_count):
            for widget in doc[index].widgets():
                name = widget.field_name
                if name is None or name not in values:
                    continue
                if (widget.field_flags or 0) & _FLAG_READONLY:
                    continue

                _set_widget_value(widget, values[name])

        if flatten:
            doc.bake(annots=False, widgets=True)

        page_count = doc.page_count
        filled = doc.tobytes(deflate=True, garbage=3)

    return {
        "page_count": page_count,
        "content_base64": base64.b64encode(filled).decode("ascii"),
    }


def _set_widget_value(widget: fitz.Widget, value: object) -> None:
    """Assign a value to one widget, coercing checkbox/radio fields to a boolean. Best-effort."""
    field_type = _FIELD_TYPES.get(widget.field_type, "unknown")

    try:
        if field_type in _CHECKBOX_TYPES:
            widget.field_value = _is_truthy(value)
        else:
            widget.field_value = _value_to_str(value)
        widget.update()
    except (ValueError, RuntimeError):
        # An invalid choice or unsupported widget: skip it rather than failing the whole fill.
        return


def _value_to_str(value: object) -> str:
    """Normalize a widget value (which may be ``None`` or a bool) to a plain string."""
    if value is None or value is False:
        return ""
    if value is True:
        return "Yes"

    return str(value)


def _is_truthy(value: object) -> bool:
    """Whether a fill value means "checked" (accepts booleans and common truthy strings)."""
    if isinstance(value, bool):
        return value

    return str(value).strip().lower() in _TRUTHY
