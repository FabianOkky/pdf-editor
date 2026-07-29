"""FastAPI application entrypoint for the PDF microservice.

Run locally (from the ``pdf-service`` folder, venv activated):

    uvicorn app.main:app --reload --port 8001
"""

from __future__ import annotations

from fastapi import FastAPI

from app.core.config import get_settings
from app.routers import ai, health, pdf

settings = get_settings()

app = FastAPI(
    title="PDF Editor — PDF Service",
    version=settings.version,
    description=(
        "Internal microservice for PDF processing (PyMuPDF), OCR, Word export, and AI glue. "
        "Called by the Laravel app over localhost with a shared-secret header."
    ),
)

app.include_router(health.router, tags=["health"])
app.include_router(pdf.router)
app.include_router(ai.router)
