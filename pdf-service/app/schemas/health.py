"""Schemas for the health endpoint."""

from __future__ import annotations

from pydantic import BaseModel


class HealthResponse(BaseModel):
    """Response body for ``GET /health``."""

    status: str
    service: str
    version: str
