"""Shared helpers for opening PDF bytes with PyMuPDF.

Centralizes error handling so corrupt or encrypted input is turned into a clean
``ValueError`` that routers map to an HTTP 422 with a friendly message.
"""

from __future__ import annotations

import fitz


def open_pdf(data: bytes) -> fitz.Document:
    """Open PDF bytes, raising :class:`ValueError` for corrupt or encrypted files.

    The caller is responsible for closing the returned document (use a ``with`` block).

    Raises:
        ValueError: when the bytes are not a readable PDF, or the PDF is encrypted.
    """
    try:
        doc = fitz.open(stream=data, filetype="pdf")
    except Exception as exc:  # PyMuPDF raises various fitz.* errors for bad input
        raise ValueError("Invalid or corrupt PDF file.") from exc

    if doc.needs_pass:
        doc.close()
        raise ValueError("PDF is encrypted/password-protected.")

    return doc
