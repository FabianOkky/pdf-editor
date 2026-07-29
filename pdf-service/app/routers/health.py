"""Health-check route — used by Laravel's ``PdfServiceClient::health()``."""

from __future__ import annotations

from fastapi import APIRouter, Depends

from app.core.auth import verify_secret
from app.core.config import Settings, get_settings
from app.schemas.health import HealthResponse

router = APIRouter()


@router.get("/health", response_model=HealthResponse)
async def health(
    _: None = Depends(verify_secret),
    settings: Settings = Depends(get_settings),
) -> HealthResponse:
    """Liveness probe. Requires a valid ``X-Pdf-Secret`` header."""
    return HealthResponse(status="ok", service=settings.app_name, version=settings.version)
