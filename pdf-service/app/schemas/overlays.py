"""Schemas for the overlay-baking endpoint (``POST /pdf/bake``).

An overlay is a non-destructive edit placed on top of a page. Every overlay carries its
geometry in **PDF user space** (points, origin bottom-left) so baking is deterministic
regardless of the on-screen zoom (ARCHITECTURE.md §3). The request is the PDF plus a JSON
array of overlays; the response is one flattened PDF.

The per-type ``payload`` shapes are validated in ``services.pdf_bake`` so this module stays
the stable wire contract mirrored by the Laravel client.
"""

from __future__ import annotations

from pydantic import BaseModel, Field


class OverlayIn(BaseModel):
    """A single overlay edit as sent by Laravel (geometry lives in ``payload``)."""

    type: str
    page_number: int = Field(ge=1)
    z_index: int = 0
    order: int = 0
    payload: dict


class BakeResponse(BaseModel):
    """The flattened PDF produced by baking the overlays onto a copy of the input."""

    page_count: int
    content_base64: str
