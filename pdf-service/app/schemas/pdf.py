"""Schemas for the PDF inspection endpoints (``/pdf/info``, ``/pdf/thumbnails``)."""

from __future__ import annotations

from pydantic import BaseModel


class PageSize(BaseModel):
    """A single page's dimensions in PDF user-space points (origin bottom-left)."""

    width: float
    height: float


class PdfInfoResponse(BaseModel):
    """Response body for ``POST /pdf/info``."""

    page_count: int
    pages: list[PageSize]
    source_type: str  # native | scanned | mixed | unknown


class Thumbnail(BaseModel):
    """A single rendered page preview, PNG bytes base64-encoded."""

    page: int  # 1-based page number
    width: int  # rendered pixel width
    height: int  # rendered pixel height
    format: str  # always "png" for now
    image_base64: str


class ThumbnailsResponse(BaseModel):
    """Response body for ``POST /pdf/thumbnails``."""

    thumbnails: list[Thumbnail]
