# Phase 6 — AI Assistant (Chat + Summarize + Translate) & OCR

**Status:** ✅ Done (Session 2026-06-22)
**Depends on:** Phase 1. Shares OCR/text-extraction with Phase 5.
**Last updated:** 2026-06-22

> **📎 Context to load — read ONLY these (saves tokens):**
> `STATUS.md` (small; what's done — check if OCR exists yet) · **this file** ·
> `ARCHITECTURE.md` §3, §4, §5, §6, §7, §8 only. For LLM model ids/pricing, use the
> `claude-api` skill instead of guessing.
> Skip `README.md` and other phase files unless a Handoff note below points you there.
>
> **Golden Rule (always):** never regenerate the PDF — edits are non-destructive overlays.
> **AI does NOT do layout editing** (that's the overlay engine). One shared text+LLM
> pipeline → Chat / Summarize / Translate are cheap once Chat exists.

## Goal

A single "AI Assistant" side panel on the document viewer offering: **Chat with PDF**
(RAG Q&A), **Summarize** (whole doc / per page), and **Translate** (to a chosen language).
OCR is used so scanned PDFs also work. Data extraction is a **stretch goal**.

## Important fidelity note

- Translate/summarize output is shown **in the panel** (and downloadable as plain text or a
  simple new document). **Do not** write translated text back onto the original PDF layout —
  text length changes break the layout. Keep AI output separate from the source rendering.

## Scope

**In:**
- Text-extraction pipeline: `POST /pdf/extract-text` (+ OCR fallback for scanned docs,
  shared with Phase 5). Chunk + embed for RAG → `document_chunks`.
- **Chat with PDF:** conversation UI (`ai_conversations` / `ai_messages`), retrieval over
  chunks, streamed LLM answers with page citations where possible.
- **Summarize:** whole-document and per-page summaries.
- **Translate:** translate extracted text to a target language; show in panel + export to
  text/simple doc.
- Provider config + cost/limits guardrails (token caps, rate limiting per user).

**Stretch:** structured **data extraction** (invoice/table → JSON/CSV with a schema).

## Open decisions to resolve here (record in STATUS decisions log) — RESOLVED 2026-06-22

- ✅ LLM provider & model → **Anthropic Claude `claude-opus-4-8`**, called from the Python
  service (`/ai/*`); key stays in `pdf-service/.env`. (See STATUS decisions log for full rationale.)
- ✅ Vector store → **portable JSON embeddings + in-process cosine** in `RagService`, NOT pgvector:
  the dev/CI Postgres has no `vector` extension available (`pg_available_extensions` is empty for it),
  so the plan's allowed in-process fallback is used. Upgrade path noted in the migration.
- ✅ Embedding model → provider-abstracted in Python; default deterministic local **`hash`** (no key,
  offline), **Voyage AI** optional for real semantic vectors.

## Tasks

- [x] OCR already built in Phase 5 — `/pdf/extract-text` reuses `/pdf/ocr` for the scanned fallback.
- [x] Python `POST /pdf/extract-text` → per-page + concatenated text (native; OCR fallback).
- [x] Chunking + embedding + storage in `document_chunks` — JSON vectors + in-process cosine
      (pgvector unavailable; the allowed fallback).
- [x] Retrieval + LLM call layer → **Python `/ai/*`** (provider key server-side only); Laravel
      orchestrates + does retrieval over its own DB.
- [x] `ai_conversations` / `ai_messages` (+ `AiMessageRole`) / `document_chunks` models + migrations + factories.
- [x] Livewire AI panel: chat, summarize, translate (language picker + page scope). (Internal
      streaming to Anthropic; panel shows a "Thinking…" state — true end-to-end streaming deferred.)
- [x] Guardrails: per-user rate limit, input-char cap, graceful errors, "AI may be wrong" notice.
- [x] Pest feature tests with the LLM + Python **faked** (deterministic): chat stores messages,
      summarize/translate return content, retrieval picks right chunks, rate limit, authorization.
- [x] pytest: extract-text (+ OCR fallback) + embeddings deterministic; LLM (`_complete`) mocked.
- [x] Tests green; Pint + larastan(L7,0) + ruff/black.
- [ ] **Stretch (NOT done):** structured data extraction (invoice/table → JSON/CSV).

## Acceptance criteria

- Chat answers questions grounded in the document (with citations where feasible), for both
  native and scanned (OCR'd) PDFs.
- Summarize and Translate produce sensible output shown in the panel (not written onto the PDF).
- No provider keys leak to the client; usage is rate-limited; failures degrade gracefully.

## Tests required

- Pest: chat/summarize/translate flows with mocked LLM, persistence, authorization, rate limit.
- pytest: text extraction + chunking; OCR path for scanned docs.

## Handoff notes (Session 2026-06-22)

**Shape of the feature.** One Livewire side panel (`App\Livewire\Documents\AiAssistant`,
`resources/views/livewire/documents/ai-assistant.blade.php`) toggled from the viewer toolbar
(`show.blade.php`, Alpine `aiOpen`). Three tabs: **Chat** (RAG), **Summarize**, **Translate**
(language `<flux:select>` + optional page scope). AI output is shown in the panel only and is
**never** written onto the PDF.

**Provider/model.** LLM = **Claude `claude-opus-4-8`**. **All model calls live in the Python
service** (`pdf-service/app/routers/ai.py` → `services/ai_llm.py`, `ai_embeddings.py`); the
`ANTHROPIC_API_KEY` lives only in `pdf-service/.env`. `ai_llm._complete` is the single Anthropic
touch-point (adaptive thinking, **internal streaming** then `get_final_message()`), so tests fake
just that. The Anthropic client + SDK import are **lazy**, so `/pdf/extract-text` and `/ai/embed`
work with no key/package. **Live chat/summarize/translate require `ANTHROPIC_API_KEY`**; without it
those endpoints 422 and the panel shows a friendly error (extract + embed still work).

**Endpoints added (ARCHITECTURE §4).** `POST /pdf/extract-text` (multipart; native per-page text,
OCR fallback via `/pdf/ocr`, graceful degrade when tessdata absent). `POST /ai/embed` (JSON; batch
→ vectors). `POST /ai/chat` (JSON; `{question, contexts:[{page_number,content}], history}` → grounded
answer with `(p. N)` citations). `POST /ai/summarize` (JSON; `{text, scope?}`). `POST /ai/translate`
(JSON; `{text, target_language}`). All mirrored on `PdfServiceClient` (`extractText/embed/chat/
summarize/translate`, all on the long timeout).

**RAG / vector store.** **pgvector is NOT used** — the dev/CI Postgres has no `vector` extension
(`SELECT … pg_available_extensions WHERE name='vector'` is empty). Embeddings are stored as a
**portable JSON float array** on `document_chunks.embedding` and ranked **in-process by cosine** in
`App\Services\RagService` (documents are owner-scoped & bounded → cheap). To upgrade: install pgvector,
swap the column to `vector(N)`, replace the cosine loop with `ORDER BY embedding <=> ?` (note in the
`create_document_chunks` migration). Embeddings come from Python `/ai/embed`, default provider **`hash`**
(deterministic signed feature-hashing, dim 256, no key); set `AI_EMBEDDING_PROVIDER=voyage` +
`VOYAGE_API_KEY` for real semantic vectors. **Switching providers ⇒ re-index** (vectors aren't
comparable). `RagService::ensureIndexed` re-indexes automatically when the document's active bytes
change (signature = `sha1(activePath())`), so a bake/restore refreshes the index.

**Data flow (chat).** `AiAssistant::ask` → `AiAssistantService::ask`: build history from prior
`ai_messages`, persist the user turn, `RagService::ensureIndexed` (extract→chunk→embed→store),
`RagService::retrieve` (embed query + cosine top-k, `AI_RETRIEVAL_TOP_K`=5), `PdfServiceClient::chat`,
persist the assistant turn with `meta.pages` = cited pages (shown as "Sources").
Summarize/translate re-extract text (`gatherText`, capped at `AI_MAX_INPUT_CHARS`), call the service,
and render in the panel (with a Copy button).

**Config.** `config/services.ai` (model, language, chunk_size/overlap, retrieval_top_k,
max_input_chars, rate_limit_per_minute). Python: `app/core/config.py` (anthropic key, `AI_MODEL`,
`AI_MAX_TOKENS`, embedding provider/dim/model, `VOYAGE_API_KEY`). `.env.example` updated on both sides.
`requirements.txt` gained `anthropic` (pure-Python, installs on 3.14; verified `anthropic==0.111.0`).

**Guardrails.** Per-user `RateLimiter` key `ai-assistant:{id}` (15/min default) on every AI action;
`AI_MAX_INPUT_CHARS` cap; every service call in try/catch → friendly error + `report()`; `authorize('view')`
on mount and each action. "AI can make mistakes" notice + "Powered by {model}" in the footer.

**Tests.** Laravel fakes the Python endpoints via `Http::fake` — helper **`fakeAi()`** in `tests/Pest.php`
(deterministic embed **closure** over a tiny vocab so retrieval/cosine is real + reproducible; plus
`fakeExtractText()`). `tests/Feature/Documents/AiAssistantTest.php` (chat persistence + citations, empty
question, summarize, translate, rate limit, clear chat, non-owner forbidden) and `RagServiceTest.php`
(index, cosine ranking, re-index skip/trigger). `PdfServiceClientTest.php` got 5 new contract tests.
pytest: `test_pdf_extract.py` + `test_ai.py` (LLM faked via `ai_llm._complete`, embeddings deterministic).
**Gate green: Pint, larastan(L7, 0), Pest 131 pass/1 skip, pytest 79, ruff/black, Vite build.**

**Gotchas for the next session.**
- `AiConversation` needs `user_id` in `#[Fillable]` (it's set server-side from `Auth::id()`).
- JSON roundtrips whole-number floats to ints — assert embeddings loosely (`toEqual`, not `toBe`).
- Run pytest with the service venv: `pdf-service/.venv/Scripts/python.exe -m pytest`.
- Heavy AI calls are **synchronous** in Livewire (long timeout + "Thinking…" spinner). For large
  scanned docs, indexing/OCR on the first question can be slow — moving indexing to a queued job is a
  reasonable Phase 7 polish. True end-to-end streaming + structured data extraction remain open.
