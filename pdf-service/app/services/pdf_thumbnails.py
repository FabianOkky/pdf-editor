"""Render PDF pages to small PNG previews, returned base64-encoded.

Used by the Laravel app to show a cover thumbnail in the document library. The viewer's
per-page thumbnail rail is rendered client-side by PDF.js, so this is mainly for covers.
"""

from __future__ import annotations

import base64

import fitz

from app.services.pdf_document import open_pdf

# Hard cap so a single request can never try to render an unreasonable number of pages.
MAX_PAGES_PER_REQUEST = 200


def render_thumbnails(
    data: bytes,
    pages: list[int] | None = None,
    dpi: int = 96,
) -> list[dict]:
    """Render the requested (1-based) pages to PNG thumbnails.

    Args:
        data: the PDF bytes.
        pages: 1-based page numbers to render; ``None`` renders every page (capped).
        dpi: render resolution; 72 dpi == the page's point size in pixels.

    Returns:
        A list of ``{page, width, height, format, image_base64}`` dicts.

    Raises:
        ValueError: for corrupt or encrypted PDFs (see :func:`open_pdf`).
    """
    zoom = max(dpi, 1) / 72.0
    matrix = fitz.Matrix(zoom, zoom)
    thumbnails: list[dict] = []

    with open_pdf(data) as doc:
        requested = pages if pages else list(range(1, doc.page_count + 1))
        for page_number in requested[:MAX_PAGES_PER_REQUEST]:
            if page_number < 1 or page_number > doc.page_count:
                continue
            pixmap = doc.load_page(page_number - 1).get_pixmap(matrix=matrix, alpha=False)
            thumbnails.append(
                {
                    "page": page_number,
                    "width": pixmap.width,
                    "height": pixmap.height,
                    "format": "png",
                    "image_base64": base64.b64encode(pixmap.tobytes("png")).decode("ascii"),
                }
            )

    return thumbnails
