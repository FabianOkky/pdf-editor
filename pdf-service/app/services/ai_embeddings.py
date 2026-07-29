"""Embeddings for RAG retrieval (``POST /ai/embed``).

Two providers, chosen by ``AI_EMBEDDING_PROVIDER``:

* ``hash`` (default) — a deterministic, dependency-free embedding using signed feature
  hashing (a hashed bag-of-words). It needs no API key and no heavy ML libraries, so the
  assistant runs offline and tests are deterministic. Retrieval quality is lexical, not deeply
  semantic — honest, but enough to ground answers in the right passages.
* ``voyage`` — Voyage AI (Anthropic's recommended embedding partner) for real semantic
  vectors. Needs ``VOYAGE_API_KEY``.

Laravel stores the returned vectors and does the similarity search itself (it owns the
database), so this endpoint is a pure, stateless embedding function.
"""

from __future__ import annotations

import hashlib
import math
import re

import httpx

from app.core.config import get_settings

_TOKEN_RE = re.compile(r"[a-z0-9]+")
_VOYAGE_URL = "https://api.voyageai.com/v1/embeddings"
_VOYAGE_TIMEOUT = 30.0
_OLLAMA_TIMEOUT = 120.0


def embed_texts(texts: list[str]) -> dict:
    """Embed ``texts`` and return ``{model, dimensions, embeddings}`` (vectors in input order).

    Raises:
        ValueError: when the configured provider is misconfigured (e.g. Voyage without a key)
            or the provider call fails.
    """
    settings = get_settings()
    provider = settings.embedding_provider.lower()

    if provider == "voyage":
        vectors = _voyage_embed(texts, settings.embedding_model, settings.voyage_api_key)
        return {
            "model": settings.embedding_model,
            "dimensions": len(vectors[0]) if vectors else 0,
            "embeddings": vectors,
        }

    if provider == "ollama":
        vectors = _ollama_embed(texts, settings.ollama_embedding_model, settings.ollama_url)
        return {
            "model": settings.ollama_embedding_model,
            "dimensions": len(vectors[0]) if vectors else 0,
            "embeddings": vectors,
        }

    dim = settings.embedding_dim
    return {
        "model": "hash",
        "dimensions": dim,
        "embeddings": [hash_embed(text, dim) for text in texts],
    }


def hash_embed(text: str, dim: int) -> list[float]:
    """A deterministic L2-normalized signed-feature-hash embedding of ``text``.

    Each token is hashed to a bucket and a sign; collisions partly cancel, which keeps the
    vector informative without any model. Identical text always yields the identical vector.
    """
    vector = [0.0] * dim
    for token in _TOKEN_RE.findall(text.lower()):
        digest = hashlib.md5(token.encode("utf-8")).digest()
        bucket = int.from_bytes(digest[:4], "big") % dim
        sign = 1.0 if digest[4] & 1 else -1.0
        vector[bucket] += sign

    norm = math.sqrt(sum(value * value for value in vector))
    if norm > 0.0:
        vector = [value / norm for value in vector]

    return vector


def _voyage_embed(texts: list[str], model: str, api_key: str) -> list[list[float]]:
    """Call Voyage AI for real semantic embeddings. Raises ``ValueError`` on misconfig/failure."""
    if not api_key:
        raise ValueError("VOYAGE_API_KEY is not configured for the PDF service.")

    try:
        response = httpx.post(
            _VOYAGE_URL,
            headers={"Authorization": f"Bearer {api_key}"},
            json={"input": texts, "model": model},
            timeout=_VOYAGE_TIMEOUT,
        )
        response.raise_for_status()
        payload = response.json()
    except (httpx.HTTPError, ValueError) as exc:
        raise ValueError(f"Embedding provider request failed: {exc}") from exc

    return [item["embedding"] for item in payload["data"]]


def _ollama_embed(texts: list[str], model: str, base_url: str) -> list[list[float]]:
    """Embed via a local Ollama daemon (``/api/embed``). Raises ``ValueError`` on failure."""
    try:
        response = httpx.post(
            f"{base_url}/api/embed",
            json={"model": model, "input": texts},
            timeout=_OLLAMA_TIMEOUT,
        )
        response.raise_for_status()
        payload = response.json()
    except (httpx.HTTPError, ValueError) as exc:
        raise ValueError(
            f"Ollama embedding request failed (is it running at {base_url}?): {exc}"
        ) from exc

    vectors = payload.get("embeddings")
    if not vectors:
        raise ValueError(f"Ollama returned no embeddings: {payload}")

    return vectors
