"""Page-level operations (organize / split / merge) with PyMuPDF — 100% fidelity.

Pages are copied with :meth:`fitz.Document.insert_pdf`, never re-rendered, so embedded
fonts, vector content and layout are preserved byte-for-byte (the Golden Rule). Each call
returns one or more freshly produced PDFs which Laravel persists as new versions/documents.
"""

from __future__ import annotations

import base64

import fitz
from pydantic import TypeAdapter, ValidationError

from app.schemas.pages import (
    MergeSpec,
    OrganizeSpec,
    PagesSpec,
    SplitSpec,
)
from app.services.pdf_document import open_pdf

_SPEC_ADAPTER: TypeAdapter[PagesSpec] = TypeAdapter(PagesSpec)


def parse_pages_spec(raw: str) -> PagesSpec:
    """Parse + validate the JSON spec from the multipart ``spec`` form field.

    Raises:
        ValueError: when the JSON is malformed or does not match a known operation.
    """
    try:
        return _SPEC_ADAPTER.validate_json(raw)
    except ValidationError as exc:
        raise ValueError("Invalid page-operation spec.") from exc


def apply_page_operation(spec: PagesSpec, files: list[bytes]) -> list[dict]:
    """Dispatch a parsed spec to the matching operation, returning a list of outputs.

    Args:
        spec: the validated operation spec.
        files: uploaded PDF bytes — exactly one for organize/split, two or more for merge.

    Raises:
        ValueError: for invalid input (wrong file count, out-of-range pages, corrupt PDF).
    """
    if isinstance(spec, OrganizeSpec):
        return [_organize(_single(files), spec)]
    if isinstance(spec, SplitSpec):
        return _split(_single(files), spec)
    if isinstance(spec, MergeSpec):
        return _merge(files)

    raise ValueError("Unsupported page operation.")  # pragma: no cover - union is exhaustive


def _single(files: list[bytes]) -> bytes:
    """Return the one expected input file, or raise if the count is wrong."""
    if len(files) != 1:
        raise ValueError("This operation needs exactly one input file.")

    return files[0]


def _organize(data: bytes, spec: OrganizeSpec) -> dict:
    """Build a new PDF from the requested pages, in order, applying per-page rotation."""
    if not spec.pages:
        raise ValueError("Keep at least one page.")

    with open_pdf(data) as src:
        page_count = src.page_count
        out = fitz.open()
        try:
            for item in spec.pages:
                if item.rotate % 90 != 0:
                    raise ValueError("Rotation must be a multiple of 90 degrees.")
                if item.source > page_count:
                    raise ValueError(
                        f"Page {item.source} is out of range (document has {page_count})."
                    )
                index = item.source - 1
                out.insert_pdf(src, from_page=index, to_page=index)
                page = out[-1]
                page.set_rotation((page.rotation + item.rotate) % 360)

            return _emit(out)
        finally:
            out.close()


def _split(data: bytes, spec: SplitSpec) -> list[dict]:
    """Split the document into one output per resolved page range."""
    with open_pdf(data) as src:
        ranges = _resolve_ranges(spec, src.page_count)
        outputs: list[dict] = []
        for start, end in ranges:
            out = fitz.open()
            try:
                out.insert_pdf(src, from_page=start - 1, to_page=end - 1)
                outputs.append(_emit(out))
            finally:
                out.close()

    return outputs


def _resolve_ranges(spec: SplitSpec, page_count: int) -> list[tuple[int, int]]:
    """Turn a split spec into concrete inclusive 1-based ``(start, end)`` ranges."""
    if page_count == 0:
        raise ValueError("Cannot split an empty document.")
    if spec.ranges is not None and spec.every is not None:
        raise ValueError("Provide either 'ranges' or 'every', not both.")

    if spec.ranges is not None:
        if not spec.ranges:
            raise ValueError("Split needs at least one range.")
        for start, end in spec.ranges:
            if start < 1 or end > page_count or start > end:
                raise ValueError(
                    f"Invalid range [{start}, {end}] for a {page_count}-page document."
                )
        return [(start, end) for start, end in spec.ranges]

    if spec.every is not None:
        return [
            (i, min(i + spec.every - 1, page_count)) for i in range(1, page_count + 1, spec.every)
        ]

    raise ValueError("Split needs 'ranges' or 'every'.")


def _merge(files: list[bytes]) -> list[dict]:
    """Concatenate every uploaded file, in order, into a single document."""
    if len(files) < 2:
        raise ValueError("Merge needs at least two input files.")

    out = fitz.open()
    try:
        for data in files:
            with open_pdf(data) as src:
                out.insert_pdf(src)

        return [_emit(out)]
    finally:
        out.close()


def _emit(doc: fitz.Document) -> dict:
    """Serialize a produced document to a ``{page_count, content_base64}`` payload."""
    data = doc.tobytes(deflate=True, garbage=3)

    return {
        "page_count": doc.page_count,
        "content_base64": base64.b64encode(data).decode("ascii"),
    }
