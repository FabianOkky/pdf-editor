<?php

namespace App\Livewire\Documents;

use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\Document;
use App\Services\AiAssistantService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Throwable;

/**
 * The "AI Assistant" side panel on the document viewer: Chat with PDF (RAG over the document's
 * indexed chunks), Summarize, and Translate. All model calls go through the Python service, so
 * provider keys stay server-side. Output is shown here only — it is never written back onto the
 * PDF layout (the Phase 6 fidelity note). Per-user rate limiting guards every AI action.
 */
class AiAssistant extends Component
{
    public Document $document;

    /** Active feature tab: chat | summarize | translate. */
    public string $tab = 'chat';

    /** Selected LLM backend (ollama | gemini | …); switchable from the panel toggle. */
    public string $provider = '';

    public string $question = '';

    public string $targetLanguage = 'English';

    /** Optional 1-based page to scope summarize/translate to; null = the whole document. */
    public ?int $scopePage = null;

    public ?int $conversationId = null;

    public ?string $summary = null;

    public ?string $summaryScope = null;

    public ?string $translation = null;

    public ?string $translationScope = null;

    public function mount(Document $document): void
    {
        $this->authorize('view', $document);

        $this->document = $document;

        $this->provider = $this->normalizeProvider(
            (string) session('ai.provider', (string) config('services.ai.default_provider'))
        );

        $this->conversationId = $document->aiConversations()
            ->where('user_id', Auth::id())
            ->latest('id')
            ->value('id');
    }

    /**
     * Remember the chosen LLM backend across requests (and reject anything not on offer).
     */
    public function updatedProvider(string $value): void
    {
        $this->provider = $this->normalizeProvider($value);
        session(['ai.provider' => $this->provider]);
    }

    /**
     * The LLM backends the toggle offers: ``key => [label, model]`` (display-only metadata).
     *
     * @return array<string, array{label: string, model: string}>
     */
    #[Computed]
    public function providers(): array
    {
        /** @var array<string, array{label: string, model: string}> $providers */
        $providers = config('services.ai.providers', []);

        return $providers;
    }

    /**
     * A valid provider key: the given one if offered, otherwise the configured default.
     */
    protected function normalizeProvider(string $value): string
    {
        $providers = (array) config('services.ai.providers', []);

        if (array_key_exists($value, $providers)) {
            return $value;
        }

        return (string) config('services.ai.default_provider');
    }

    /**
     * The provider to send with an AI call, always re-validated against the offered list (the
     * public property could be tampered with from the client).
     */
    protected function resolvedProvider(): string
    {
        return $this->normalizeProvider($this->provider);
    }

    /**
     * The current conversation's messages, oldest first (empty until the first question).
     *
     * @return Collection<int, AiMessage>
     */
    #[Computed]
    public function messages(): Collection
    {
        if ($this->conversationId === null) {
            return collect();
        }

        return AiMessage::where('conversation_id', $this->conversationId)->oldest('id')->get();
    }

    /**
     * The model name shown in the panel footer ("Powered by …") for the selected backend.
     * Informational only — the real model is configured in the Python service.
     */
    #[Computed]
    public function model(): string
    {
        return (string) config(
            "services.ai.providers.{$this->provider}.model",
            config('services.ai.model'),
        );
    }

    /**
     * Send a chat question: ground it in the document and append the assistant's reply.
     */
    public function ask(AiAssistantService $assistant): void
    {
        $this->authorize('view', $this->document);

        $question = trim($this->question);

        if ($question === '') {
            return;
        }

        if ($this->rateLimited('question')) {
            return;
        }

        try {
            $assistant->ask($this->conversation($question), $question, $this->resolvedProvider());
        } catch (Throwable $exception) {
            report($exception);
            $this->addError('question', __('The assistant could not answer just now. Please try again.'));

            return;
        }

        $this->question = '';
        unset($this->messages);
    }

    /**
     * Summarize the whole document, or the chosen page, into the panel.
     */
    public function summarizeDocument(AiAssistantService $assistant): void
    {
        $this->authorize('view', $this->document);

        if (! $this->scopePageIsValid('summary') || $this->rateLimited('summary')) {
            return;
        }

        $this->summary = null;

        try {
            $result = $assistant->summarize($this->document, $this->pageScope(), $this->resolvedProvider());
        } catch (Throwable $exception) {
            report($exception);
            $this->addError('summary', __('We could not summarize this document. Please try again.'));

            return;
        }

        $this->summary = $result['summary'];
        $this->summaryScope = $result['scope'];
    }

    /**
     * Translate the whole document, or the chosen page, into the selected language.
     */
    public function translateDocument(AiAssistantService $assistant): void
    {
        $this->authorize('view', $this->document);

        if (trim($this->targetLanguage) === '') {
            $this->addError('translation', __('Choose a language to translate into.'));

            return;
        }

        if (! $this->scopePageIsValid('translation') || $this->rateLimited('translation')) {
            return;
        }

        $this->translation = null;

        try {
            $result = $assistant->translate($this->document, trim($this->targetLanguage), $this->pageScope(), $this->resolvedProvider());
        } catch (Throwable $exception) {
            report($exception);
            $this->addError('translation', __('We could not translate this document. Please try again.'));

            return;
        }

        $this->translation = $result['translated'];
        $this->translationScope = $result['scope'];
    }

    /**
     * Start a fresh chat: delete the current conversation (messages cascade) and reset state.
     */
    public function clearChat(): void
    {
        $this->authorize('view', $this->document);

        $this->document->aiConversations()
            ->where('user_id', Auth::id())
            ->whereKey($this->conversationId)
            ->delete();

        $this->conversationId = null;
        unset($this->messages);
    }

    public function render(): View
    {
        return view('livewire.documents.ai-assistant');
    }

    /**
     * The conversation to append to, creating (and remembering) one on the first question.
     */
    protected function conversation(string $question): AiConversation
    {
        $conversation = $this->document->aiConversations()
            ->where('user_id', Auth::id())
            ->whereKey($this->conversationId)
            ->first();

        if ($conversation === null) {
            $conversation = $this->document->aiConversations()->create([
                'user_id' => Auth::id(),
                'title' => Str::limit($question, 40),
            ]);

            $this->conversationId = $conversation->id;
        }

        return $conversation;
    }

    /**
     * The optional page scope, normalized: a blank/zero input means "the whole document" (null).
     */
    protected function pageScope(): ?int
    {
        return $this->scopePage !== null && $this->scopePage > 0 ? $this->scopePage : null;
    }

    /**
     * Validate the optional page scope is within the document, adding an error if not.
     */
    protected function scopePageIsValid(string $field): bool
    {
        $page = $this->pageScope();

        if ($page === null) {
            return true;
        }

        if ($page > $this->document->activePageCount()) {
            $this->addError($field, __('Enter a page between 1 and :max.', ['max' => $this->document->activePageCount()]));

            return false;
        }

        return true;
    }

    /**
     * Per-user guardrail across all AI actions: cap requests per minute, adding an error when hit.
     */
    protected function rateLimited(string $field): bool
    {
        $key = 'ai-assistant:'.Auth::id();
        $max = max(1, (int) config('services.ai.rate_limit_per_minute'));

        if (RateLimiter::tooManyAttempts($key, $max)) {
            $this->addError($field, __('You are sending AI requests too quickly. Please wait :seconds seconds.', [
                'seconds' => RateLimiter::availableIn($key),
            ]));

            return true;
        }

        RateLimiter::hit($key, 60);

        return false;
    }
}
