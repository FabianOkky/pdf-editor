"""Shared-secret authentication for service-to-service calls.

Laravel attaches the ``X-Pdf-Secret`` header on every request (see ``PdfServiceClient``).
The service binds to localhost only, but the secret stops other local processes from
calling it. Use this as a FastAPI dependency on protected routes.
"""

from __future__ import annotations

import secrets

from fastapi import Header, HTTPException, status

from app.core.config import get_settings


async def verify_secret(x_pdf_secret: str | None = Header(default=None)) -> None:
    """Reject requests whose ``X-Pdf-Secret`` header is missing or does not match.

    Raises:
        HTTPException: 401 when the header is absent or incorrect, or when no secret is
            configured on the service (fail closed).
    """
    expected = get_settings().secret
    if not expected or not x_pdf_secret or not secrets.compare_digest(x_pdf_secret, expected):
        raise HTTPException(
            status_code=status.HTTP_401_UNAUTHORIZED,
            detail="Invalid or missing X-Pdf-Secret header.",
        )
