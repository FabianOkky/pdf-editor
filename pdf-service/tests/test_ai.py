"""Tests for the AI assistant endpoints (``/ai/embed``, ``/ai/chat``, ``/ai/summarize``,
``/ai/translate``).

The single Anthropic touch-point (``ai_llm._complete``) is faked, so the real prompt-building
runs but no provider call is made — tests are deterministic and need no API key. Embeddings use
the local ``hash`` provider, which is deterministic by construction.
"""

from __future__ import annotations

import math

from app.core.config import get_settings
from app.services import ai_llm

# --- /ai/embed -------------------------------------------------------------------------------


def test_embed_requires_secret(client):
    assert client.post("/ai/embed", json={"texts": ["hello"]}).status_code == 401


def test_embed_rejects_empty_batch(client, secret):
    response = client.post("/ai/embed", headers={"X-Pdf-Secret": secret}, json={"texts": []})
    assert response.status_code == 422


def test_embed_is_deterministic_and_normalized(client, secret):
    response = client.post(
        "/ai/embed",
        headers={"X-Pdf-Secret": secret},
        json={"texts": ["hello world", "hello world", "a totally different sentence"]},
    )
    body = response.json()

    assert body["model"] == "hash"
    assert body["dimensions"] == 256
    assert len(body["embeddings"]) == 3
    # Identical text → identical vector; different text → different vector.
    assert body["embeddings"][0] == body["embeddings"][1]
    assert body["embeddings"][0] != body["embeddings"][2]
    # Vectors are L2-normalized.
    norm = math.sqrt(sum(value * value for value in body["embeddings"][0]))
    assert abs(norm - 1.0) < 1e-6


def test_embed_voyage_without_key_is_unprocessable(client, secret, monkeypatch):
    settings = get_settings()
    monkeypatch.setattr(settings, "embedding_provider", "voyage")
    monkeypatch.setattr(settings, "voyage_api_key", "")

    response = client.post("/ai/embed", headers={"X-Pdf-Secret": secret}, json={"texts": ["hello"]})
    assert response.status_code == 422


# --- /ai/chat --------------------------------------------------------------------------------


def test_chat_requires_secret(client):
    assert client.post("/ai/chat", json={"question": "hi"}).status_code == 401


def test_chat_returns_grounded_answer(client, secret, monkeypatch):
    captured = {}

    def fake_complete(system, messages, max_tokens=None, provider=None):
        captured["system"] = system
        captured["messages"] = messages
        captured["provider"] = provider
        return "The total is 162342 (p. 3)."

    monkeypatch.setattr(ai_llm, "_complete", fake_complete)

    response = client.post(
        "/ai/chat",
        headers={"X-Pdf-Secret": secret},
        json={
            "question": "What is the total amount?",
            "contexts": [{"page_number": 3, "content": "Total Amount 162342"}],
            "history": [
                {"role": "user", "content": "hi"},
                {"role": "assistant", "content": "Hello!"},
            ],
        },
    )
    body = response.json()

    assert body["answer"] == "The total is 162342 (p. 3)."
    assert body["model"] == "claude-opus-4-8"
    # The grounded prompt carries the page-tagged excerpt and the question; history is replayed.
    assert "page" in captured["system"].lower()
    assert captured["messages"][0] == {"role": "user", "content": "hi"}
    assert "[Page 3]" in captured["messages"][-1]["content"]
    assert "What is the total amount?" in captured["messages"][-1]["content"]


def test_chat_without_context_still_answers(client, secret, monkeypatch):
    monkeypatch.setattr(
        ai_llm,
        "_complete",
        lambda system, messages, max_tokens=None, provider=None: "Not found in the document.",
    )

    response = client.post(
        "/ai/chat",
        headers={"X-Pdf-Secret": secret},
        json={"question": "Anything?", "contexts": [], "history": []},
    )
    assert response.json()["answer"] == "Not found in the document."


# --- /ai/summarize ---------------------------------------------------------------------------


def test_summarize_returns_summary(client, secret, monkeypatch):
    captured = {}

    def fake_complete(system, messages, max_tokens=None, provider=None):
        captured["messages"] = messages
        return "A short summary."

    monkeypatch.setattr(ai_llm, "_complete", fake_complete)

    response = client.post(
        "/ai/summarize",
        headers={"X-Pdf-Secret": secret},
        json={"text": "Long document body about quarterly results.", "scope": "page 2"},
    )
    body = response.json()

    assert body["summary"] == "A short summary."
    assert body["model"] == "claude-opus-4-8"
    assert "page 2" in captured["messages"][0]["content"]
    assert "quarterly results" in captured["messages"][0]["content"]


# --- /ai/translate ---------------------------------------------------------------------------


def test_translate_returns_translation(client, secret, monkeypatch):
    captured = {}

    def fake_complete(system, messages, max_tokens=None, provider=None):
        captured["messages"] = messages
        return "Bonjour le monde."

    monkeypatch.setattr(ai_llm, "_complete", fake_complete)

    response = client.post(
        "/ai/translate",
        headers={"X-Pdf-Secret": secret},
        json={"text": "Hello world.", "target_language": "French"},
    )
    body = response.json()

    assert body["translated"] == "Bonjour le monde."
    assert body["target_language"] == "French"
    assert body["model"] == "claude-opus-4-8"
    assert "French" in captured["messages"][0]["content"]


# --- provider toggle -------------------------------------------------------------------------


def test_request_provider_overrides_default_and_reports_its_model(client, secret, monkeypatch):
    """A ``provider`` in the request picks that backend and reports its model name."""
    captured = {}

    def fake_complete(system, messages, max_tokens=None, provider=None):
        captured["provider"] = provider
        return "Resumen breve."

    monkeypatch.setattr(ai_llm, "_complete", fake_complete)

    response = client.post(
        "/ai/summarize",
        headers={"X-Pdf-Secret": secret},
        json={"text": "Long body.", "provider": "gemini"},
    )
    body = response.json()

    assert captured["provider"] == "gemini"
    assert body["model"] == get_settings().gemini_model


def test_request_rejects_an_unknown_provider(client, secret):
    response = client.post(
        "/ai/summarize",
        headers={"X-Pdf-Secret": secret},
        json={"text": "Long body.", "provider": "not-a-provider"},
    )
    assert response.status_code == 422


def test_complete_dispatches_on_request_provider(monkeypatch):
    """``_complete`` routes to the provider passed in, ignoring the configured default."""
    monkeypatch.setattr(get_settings(), "ai_provider", "anthropic")
    monkeypatch.setattr(
        ai_llm, "_complete_ollama", lambda system, messages, max_tokens: "from-ollama"
    )

    assert ai_llm._complete("sys", [{"role": "user", "content": "hi"}], provider="ollama") == (
        "from-ollama"
    )
