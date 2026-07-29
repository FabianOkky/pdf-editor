"""Schemas for the AcroForm endpoints (``POST /pdf/form-fields`` and ``/form-fields/fill``).

Detection lists a PDF's interactive form fields; filling sets their values (optionally
flattening the widgets into static page content). Field geometry is returned in **PDF user
space** (points, origin bottom-left) so the editor can position inputs with the same
coordinate transform it uses for overlays (ARCHITECTURE.md §3).
"""

from __future__ import annotations

from pydantic import BaseModel


class FormFieldOut(BaseModel):
    """One detected AcroForm field. ``x/y/width/height`` are PDF user space (bottom-left)."""

    name: str
    type: str  # text | checkbox | radio | combobox | listbox | signature | button | unknown
    value: str = ""
    page_number: int
    x: float
    y: float
    width: float
    height: float
    options: list[str] = []
    readonly: bool = False
    required: bool = False


class FormFieldsResponse(BaseModel):
    """The interactive fields detected on a PDF (empty when it carries no AcroForm)."""

    is_form: bool
    fields: list[FormFieldOut]


class FormFillResponse(BaseModel):
    """The PDF produced by filling form values (optionally flattened) onto a copy."""

    page_count: int
    content_base64: str
