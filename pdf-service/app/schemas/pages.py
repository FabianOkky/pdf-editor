"""Schemas for the page-operations endpoint (``POST /pdf/pages``).

The request is a discriminated union on ``op``. ``organize`` covers reorder + rotate +
delete in a single primitive (a page is deleted by omitting it; reordered by its position
in the list; rotated via its ``rotate`` delta). ``split`` and ``merge`` are separate shapes.
See ARCHITECTURE.md §4.
"""

from __future__ import annotations

from typing import Annotated, Literal

from pydantic import BaseModel, Field


class OrganizePageItem(BaseModel):
    """One output page: which input page to copy, and how far to rotate it."""

    source: int = Field(ge=1)  # 1-based page number in the (single) input file
    rotate: int = 0  # clockwise degrees ADDED to the page's current rotation (multiple of 90)


class OrganizeSpec(BaseModel):
    """Reorder / rotate / delete in one pass — the desired final page list, in order."""

    op: Literal["organize"]
    pages: list[OrganizePageItem]


class SplitSpec(BaseModel):
    """Split one document into several. Provide exactly one of ``ranges`` or ``every``."""

    op: Literal["split"]
    ranges: list[tuple[int, int]] | None = None  # inclusive 1-based [start, end] pairs
    every: int | None = Field(default=None, ge=1)  # chunk into groups of N pages


class MergeSpec(BaseModel):
    """Merge the uploaded files (in the given order) into a single document."""

    op: Literal["merge"]


PagesSpec = Annotated[
    OrganizeSpec | SplitSpec | MergeSpec,
    Field(discriminator="op"),
]


class PageOpOutput(BaseModel):
    """A single produced PDF, base64-encoded, with its page count."""

    page_count: int
    content_base64: str


class PagesResponse(BaseModel):
    """Response body for ``POST /pdf/pages`` — one output for organize/merge, many for split."""

    outputs: list[PageOpOutput]
