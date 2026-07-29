"""Application configuration loaded from environment variables.

Values come from the environment (or a local ``pdf-service/.env`` file) so the service can
be configured without code changes. ``secret`` MUST match Laravel's ``PDF_SERVICE_SECRET``.
"""

from __future__ import annotations

import os
from functools import lru_cache
from pathlib import Path

from dotenv import load_dotenv

# Load a local .env file if present (no-op in production where env is set directly).
load_dotenv()

# Repository-relative defaults: ``app/core/config.py`` → service root is two parents up.
_SERVICE_ROOT = Path(__file__).resolve().parents[2]


class Settings:
    """Runtime settings for the PDF service."""

    def __init__(self) -> None:
        self.app_name: str = os.getenv("PDF_SERVICE_NAME", "pdf-service")
        self.version: str = os.getenv("PDF_SERVICE_VERSION", "0.1.0")
        self.environment: str = os.getenv("PDF_SERVICE_ENV", "local")
        self.secret: str = os.getenv("PDF_SERVICE_SECRET", "")

        # OCR (Word export & AI). PyMuPDF's bundled Tesseract only needs the language data
        # files (e.g. ``eng.traineddata``); point ``tessdata_prefix`` at the folder holding
        # them. Defaults to ``pdf-service/tessdata`` (download steps in the README).
        self.tessdata_prefix: str = os.getenv(
            "PDF_TESSDATA_PREFIX", str(_SERVICE_ROOT / "tessdata")
        )
        self.ocr_language: str = os.getenv("PDF_OCR_LANGUAGE", "eng")
        self.ocr_dpi: int = int(os.getenv("PDF_OCR_DPI", "200"))
        # Where OCR results are cached (keyed by content hash) so re-OCR of the same bytes is
        # skipped. Best-effort: a missing/unwritable directory just disables caching.
        self.ocr_cache_dir: str = os.getenv("PDF_OCR_CACHE_DIR", str(_SERVICE_ROOT / ".ocr_cache"))

        # AI assistant (Phase 6). The LLM provider key lives here, server-side only — it never
        # reaches Laravel or the browser. ``AI_PROVIDER`` selects the backend for chat /
        # summarize / translate: "anthropic" (Claude), "gemini" (Google AI Studio), or
        # "ollama" (a local Ollama daemon — offline, no API key).
        self.ai_provider: str = os.getenv("AI_PROVIDER", "anthropic").lower()
        self.anthropic_api_key: str = os.getenv("ANTHROPIC_API_KEY", "")
        self.ai_model: str = os.getenv("AI_MODEL", "claude-opus-4-8")
        # Google Gemini (AI Studio REST API). Needs GEMINI_API_KEY.
        self.gemini_api_key: str = os.getenv("GEMINI_API_KEY", "")
        self.gemini_model: str = os.getenv("GEMINI_MODEL", "gemini-2.0-flash")
        # Ollama (local). Points at a running Ollama daemon; no key required.
        self.ollama_url: str = os.getenv("OLLAMA_URL", "http://127.0.0.1:11434").rstrip("/")
        self.ollama_model: str = os.getenv("OLLAMA_MODEL", "qwen2.5:3b")
        # Output cap per LLM call; the service streams the call internally so large outputs do
        # not hit HTTP timeouts (the result is still returned whole to Laravel).
        self.ai_max_tokens: int = int(os.getenv("AI_MAX_TOKENS", "2048"))

        # Embeddings for RAG. ``hash`` is a deterministic, dependency-free local embedding
        # (signed feature hashing — good for lexical retrieval and offline/CI use). ``voyage``
        # calls Voyage AI (Anthropic's recommended embedding partner) for real semantic vectors.
        # ``ollama`` uses a local Ollama embedding model (e.g. ``nomic-embed-text``).
        self.embedding_provider: str = os.getenv("AI_EMBEDDING_PROVIDER", "hash")
        self.embedding_model: str = os.getenv("AI_EMBEDDING_MODEL", "voyage-3.5-lite")
        self.embedding_dim: int = int(os.getenv("AI_EMBEDDING_DIM", "256"))
        self.voyage_api_key: str = os.getenv("VOYAGE_API_KEY", "")
        self.ollama_embedding_model: str = os.getenv("OLLAMA_EMBEDDING_MODEL", "nomic-embed-text")

    def model_for(self, provider: str | None = None) -> str:
        """The model name for ``provider`` (or the configured default) — for response metadata."""
        provider = (provider or self.ai_provider).lower()
        if provider == "gemini":
            return self.gemini_model
        if provider == "ollama":
            return self.ollama_model
        return self.ai_model

    @property
    def active_ai_model(self) -> str:
        """The model name for the default LLM provider (for response metadata)."""
        return self.model_for()


@lru_cache
def get_settings() -> Settings:
    """Return a process-wide cached :class:`Settings` instance."""
    return Settings()
