"""AI assistant routes (``POST /ai/embed``, ``/ai/chat``, ``/ai/summarize``, ``/ai/translate``).

These take JSON bodies and require the shared secret. Embeddings are deterministic/local by
default; chat/summarize/translate call Claude. Laravel does retrieval over its own database and
sends the selected context here, so the service stays stateless. See ARCHITECTURE.md §4.
"""

from __future__ import annotations

from fastapi import APIRouter, Depends, HTTPException

from app.core.auth import verify_secret
from app.schemas.ai import (
    ChatRequest,
    ChatResponse,
    EmbedRequest,
    EmbedResponse,
    SummarizeRequest,
    SummarizeResponse,
    TranslateRequest,
    TranslateResponse,
)
from app.services.ai_embeddings import embed_texts
from app.services.ai_llm import chat, summarize, translate

router = APIRouter(prefix="/ai", tags=["ai"], dependencies=[Depends(verify_secret)])


@router.post("/embed", response_model=EmbedResponse)
def ai_embed(payload: EmbedRequest) -> EmbedResponse:
    """Embed a batch of texts into vectors (document chunks on ingest, or a query on search)."""
    try:
        result = embed_texts(payload.texts)
    except ValueError as exc:
        raise HTTPException(422, detail=str(exc)) from exc

    return EmbedResponse(**result)


@router.post("/chat", response_model=ChatResponse)
def ai_chat(payload: ChatRequest) -> ChatResponse:
    """Answer a question grounded in the supplied context excerpts (with page citations)."""
    try:
        result = chat(
            payload.question,
            [chunk.model_dump() for chunk in payload.contexts],
            [message.model_dump() for message in payload.history],
            provider=payload.provider,
        )
    except ValueError as exc:
        raise HTTPException(422, detail=str(exc)) from exc

    return ChatResponse(**result)


@router.post("/summarize", response_model=SummarizeResponse)
def ai_summarize(payload: SummarizeRequest) -> SummarizeResponse:
    """Summarize the supplied text (whole document or a single page)."""
    try:
        result = summarize(payload.text, payload.scope, provider=payload.provider)
    except ValueError as exc:
        raise HTTPException(422, detail=str(exc)) from exc

    return SummarizeResponse(**result)


@router.post("/translate", response_model=TranslateResponse)
def ai_translate(payload: TranslateRequest) -> TranslateResponse:
    """Translate the supplied text into the requested target language."""
    try:
        result = translate(payload.text, payload.target_language, provider=payload.provider)
    except ValueError as exc:
        raise HTTPException(422, detail=str(exc)) from exc

    return TranslateResponse(**result)
