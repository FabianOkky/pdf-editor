"""LLM glue for the AI assistant: chat, summarize, translate (``POST /ai/*``).

Generation is routed to the provider chosen by ``AI_PROVIDER``: Anthropic Claude (default),
Google Gemini, or a local Ollama daemon. The provider key (if any) lives only in this service.
The complete text is returned whole to Laravel, which renders it in the panel — AI output is
never written back onto the PDF.

``_complete`` is the single point that talks to a provider, so tests fake just that function and
still exercise the real prompt-building. Provider clients/imports are created lazily, so the
embeddings/extraction endpoints work even without any LLM package or key.
"""

from __future__ import annotations

import httpx

from app.core.config import get_settings

_CLIENT = None
_GEMINI_TIMEOUT = 120.0
_OLLAMA_TIMEOUT = 300.0

_CHAT_SYSTEM = (
    "You are an assistant that answers questions about the user's PDF document. "
    "Use ONLY the provided context excerpts to answer. Each excerpt is tagged with its page "
    "like [Page N]. Cite the page(s) you relied on in parentheses, e.g. (p. 3). If the answer "
    "is not contained in the context, say you could not find it in the document rather than "
    "guessing. Be concise and accurate."
)

_SUMMARIZE_SYSTEM = (
    "You summarize documents faithfully. Produce a clear, well-structured summary that "
    "captures the key points, decisions, figures, and conclusions. Do not invent information "
    "that is not present in the text. Use short paragraphs or bullet points."
)

_TRANSLATE_SYSTEM = (
    "You are a professional translator. Translate the user's text into the requested target "
    "language, preserving meaning, tone, numbers, and structure. Output only the translation, "
    "with no commentary, notes, or the original text."
)


def chat(
    question: str, contexts: list[dict], history: list[dict], provider: str | None = None
) -> dict:
    """Answer ``question`` grounded in ``contexts`` (each ``{page_number, content}``).

    ``history`` is the prior conversation as ``{role, content}`` dicts (user/assistant turns).
    ``provider`` overrides the configured default backend for this one call (the UI toggle).
    """
    messages: list[dict] = [
        {"role": message["role"], "content": message["content"]}
        for message in history
        if message.get("role") in ("user", "assistant") and message.get("content")
    ]
    messages.append({"role": "user", "content": _chat_user_prompt(question, contexts)})

    answer = _complete(_CHAT_SYSTEM, messages, provider=provider)

    return {"answer": answer, "model": get_settings().model_for(provider)}


def summarize(text: str, scope: str | None = None, provider: str | None = None) -> dict:
    """Summarize ``text``; ``scope`` is an optional label (e.g. ``"page 3"``) for the request."""
    target = f" ({scope})" if scope else ""
    prompt = f"Summarize the following document{target}:\n\n{text}"

    summary = _complete(_SUMMARIZE_SYSTEM, [{"role": "user", "content": prompt}], provider=provider)

    return {"summary": summary, "model": get_settings().model_for(provider)}


def translate(text: str, target_language: str, provider: str | None = None) -> dict:
    """Translate ``text`` into ``target_language`` (a natural-language name, e.g. ``"French"``)."""
    prompt = f"Translate the following text into {target_language}:\n\n{text}"

    translated = _complete(
        _TRANSLATE_SYSTEM, [{"role": "user", "content": prompt}], provider=provider
    )

    return {
        "translated": translated,
        "target_language": target_language,
        "model": get_settings().model_for(provider),
    }


def _chat_user_prompt(question: str, contexts: list[dict]) -> str:
    """Build the grounded user turn: the context excerpts, then the question."""
    if contexts:
        excerpts = "\n\n".join(
            f"[Page {chunk['page_number']}]\n{chunk['content']}" for chunk in contexts
        )
        context_block = f"Context excerpts from the document:\n\n{excerpts}"
    else:
        context_block = "No relevant excerpts were found in the document."

    return f"{context_block}\n\nQuestion: {question}"


def _complete(
    system: str,
    messages: list[dict],
    max_tokens: int | None = None,
    provider: str | None = None,
) -> str:
    """Run one LLM completion and return its text. The single point that calls a provider.

    Dispatches on ``provider`` (the per-request UI toggle) falling back to ``AI_PROVIDER``.
    ``messages`` is a list of ``{role, content}`` user/assistant turns; ``system`` is the system
    prompt. Raises ``ValueError`` on a missing key / failed call.
    """
    provider = (provider or get_settings().ai_provider).lower()

    if provider == "gemini":
        return _complete_gemini(system, messages, max_tokens)
    if provider == "ollama":
        return _complete_ollama(system, messages, max_tokens)

    return _complete_anthropic(system, messages, max_tokens)


# --- Anthropic Claude -----------------------------------------------------------------------


def _complete_anthropic(system: str, messages: list[dict], max_tokens: int | None) -> str:
    """One Claude completion. Streams internally so long inputs/outputs don't trip timeouts."""
    settings = get_settings()
    client = _get_client()

    with client.messages.stream(
        model=settings.ai_model,
        max_tokens=max_tokens or settings.ai_max_tokens,
        system=system,
        thinking={"type": "adaptive"},
        messages=messages,
    ) as stream:
        message = stream.get_final_message()

    parts = [block.text for block in message.content if getattr(block, "type", None) == "text"]

    return "".join(parts).strip()


def _get_client():
    """Lazily build the Anthropic client (and import the SDK), caching it process-wide."""
    global _CLIENT
    if _CLIENT is None:
        settings = get_settings()
        if not settings.anthropic_api_key:
            raise ValueError("ANTHROPIC_API_KEY is not configured for the PDF service.")
        import anthropic  # imported lazily so non-AI endpoints don't require the package

        _CLIENT = anthropic.Anthropic(api_key=settings.anthropic_api_key)

    return _CLIENT


# --- Google Gemini --------------------------------------------------------------------------


def _complete_gemini(system: str, messages: list[dict], max_tokens: int | None) -> str:
    """One Gemini completion via the AI Studio REST API. Raises ``ValueError`` on misconfig."""
    settings = get_settings()
    if not settings.gemini_api_key:
        raise ValueError("GEMINI_API_KEY is not configured for the PDF service.")

    # Gemini uses roles "user" / "model" (assistant → model) and a separate systemInstruction.
    contents = [
        {
            "role": "model" if message["role"] == "assistant" else "user",
            "parts": [{"text": message["content"]}],
        }
        for message in messages
    ]
    payload = {
        "systemInstruction": {"parts": [{"text": system}]},
        "contents": contents,
        "generationConfig": {"maxOutputTokens": max_tokens or settings.ai_max_tokens},
    }
    url = (
        f"https://generativelanguage.googleapis.com/v1beta/models/"
        f"{settings.gemini_model}:generateContent"
    )

    try:
        response = httpx.post(
            url,
            params={"key": settings.gemini_api_key},
            json=payload,
            timeout=_GEMINI_TIMEOUT,
        )
        response.raise_for_status()
        data = response.json()
    except httpx.HTTPStatusError as exc:
        raise ValueError(
            f"Gemini request failed ({exc.response.status_code}): {exc.response.text}"
        ) from exc
    except (httpx.HTTPError, ValueError) as exc:
        raise ValueError(f"Gemini request failed: {exc}") from exc

    candidates = data.get("candidates") or []
    if not candidates:
        raise ValueError(f"Gemini returned no candidates: {data}")
    parts = candidates[0].get("content", {}).get("parts", [])

    return "".join(part.get("text", "") for part in parts).strip()


# --- Ollama (local) -------------------------------------------------------------------------


def _complete_ollama(system: str, messages: list[dict], max_tokens: int | None) -> str:
    """One completion from a local Ollama daemon (``/api/chat``). Raises ``ValueError`` on error."""
    settings = get_settings()
    chat_messages = [{"role": "system", "content": system}, *messages]
    payload = {
        "model": settings.ollama_model,
        "messages": chat_messages,
        "stream": False,
        "options": {"num_predict": max_tokens or settings.ai_max_tokens},
    }

    try:
        response = httpx.post(
            f"{settings.ollama_url}/api/chat",
            json=payload,
            timeout=_OLLAMA_TIMEOUT,
        )
        response.raise_for_status()
        data = response.json()
    except httpx.HTTPError as exc:
        raise ValueError(
            f"Ollama request failed (is it running at {settings.ollama_url}?): {exc}"
        ) from exc

    return (data.get("message", {}).get("content") or "").strip()
