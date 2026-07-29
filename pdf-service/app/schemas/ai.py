"""Schemas for the AI assistant endpoints (``POST /ai/*``).

These take JSON bodies (not multipart) — Laravel does retrieval over its own database and
sends the already-selected context here, so the service stays stateless. The LLM provider key
lives only in this service. Output stays text — it is shown in the panel, never written back
onto the PDF (the fidelity note in the Phase 6 plan).
"""

from __future__ import annotations

from typing import Literal

from pydantic import BaseModel, Field

# The LLM backends the UI may select per request; ``None`` uses the service default (AI_PROVIDER).
Provider = Literal["anthropic", "gemini", "ollama"]


class EmbedRequest(BaseModel):
    """A batch of texts to embed (document chunks on ingest, or a single query on search)."""

    texts: list[str] = Field(min_length=1)


class EmbedResponse(BaseModel):
    """The embedding vectors, in the same order as the request texts."""

    model: str
    dimensions: int
    embeddings: list[list[float]]


class ContextChunk(BaseModel):
    """One retrieved excerpt the answer must be grounded in, tagged with its page."""

    page_number: int
    content: str


class ChatMessage(BaseModel):
    """A prior turn in the conversation (role is ``user`` or ``assistant``)."""

    role: str
    content: str


class ChatRequest(BaseModel):
    """A grounded question: the user's question, retrieved context, and prior turns."""

    question: str
    contexts: list[ContextChunk] = Field(default_factory=list)
    history: list[ChatMessage] = Field(default_factory=list)
    provider: Provider | None = None


class ChatResponse(BaseModel):
    """The assistant's grounded answer."""

    answer: str
    model: str


class SummarizeRequest(BaseModel):
    """Text to summarize, with an optional human-readable scope label (e.g. ``"page 3"``)."""

    text: str
    scope: str | None = None
    provider: Provider | None = None


class SummarizeResponse(BaseModel):
    """The produced summary."""

    summary: str
    model: str


class TranslateRequest(BaseModel):
    """Text to translate into ``target_language`` (a natural-language name, e.g. ``"French"``)."""

    text: str
    target_language: str
    provider: Provider | None = None


class TranslateResponse(BaseModel):
    """The translated text."""

    translated: str
    target_language: str
    model: str
