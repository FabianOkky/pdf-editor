<?php

namespace App\Services;

use App\Enums\AiMessageRole;
use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\Document;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * High-level AI assistant orchestration sitting between the Livewire panel and the Python
 * service: grounded chat (RAG), summarize, and translate. Provider keys never leave the Python
 * service. AI output is returned for display in the panel and is **never** written back onto the
 * PDF layout (the Phase 6 fidelity note).
 */
class AiAssistantService
{
    public function __construct(
        protected PdfServiceClient $pdf,
        protected RagService $rag,
    ) {}

    /**
     * Answer a question in a conversation: persist the user's turn, retrieve grounding context,
     * ask the model, then persist and return the assistant's reply (tagged with cited pages).
     */
    public function ask(AiConversation $conversation, string $question, ?string $provider = null): AiMessage
    {
        $document = $conversation->document;

        $history = array_values($conversation->messages()
            ->get()
            ->map(fn (AiMessage $message): array => [
                'role' => $message->role->value,
                'content' => $message->content,
            ])
            ->all());

        $conversation->messages()->create([
            'role' => AiMessageRole::User,
            'content' => $question,
        ]);

        $this->rag->ensureIndexed($document);
        $contexts = $this->rag->retrieve($document, $question);

        $contextPayload = array_map(
            fn (array $chunk): array => [
                'page_number' => $chunk['page_number'],
                'content' => $chunk['content'],
            ],
            $contexts,
        );

        $result = $this->pdf->chat($question, $contextPayload, $history, $provider);

        $pages = collect($contexts)
            ->pluck('page_number')
            ->unique()
            ->sort()
            ->values()
            ->all();

        return $conversation->messages()->create([
            'role' => AiMessageRole::Assistant,
            'content' => $result['answer'],
            'meta' => $pages === [] ? null : ['pages' => $pages],
        ]);
    }

    /**
     * Summarize the whole document, or a single page when ``$page`` is given. The summary is
     * returned for the panel; it is never written onto the PDF.
     *
     * @return array{summary: string, scope: string|null}
     */
    public function summarize(Document $document, ?int $page = null, ?string $provider = null): array
    {
        [$text, $scope] = $this->gatherText($document, $page);

        $result = $this->pdf->summarize($text, $scope, $provider);

        return ['summary' => $result['summary'], 'scope' => $scope];
    }

    /**
     * Translate the whole document, or a single page when ``$page`` is given, into
     * ``$targetLanguage`` (a natural-language name, e.g. "French"). Shown in the panel only.
     *
     * @return array{translated: string, target_language: string, scope: string|null}
     */
    public function translate(Document $document, string $targetLanguage, ?int $page = null, ?string $provider = null): array
    {
        [$text, $scope] = $this->gatherText($document, $page);

        $result = $this->pdf->translate($text, $targetLanguage, $provider);

        return [
            'translated' => $result['translated'],
            'target_language' => $result['target_language'],
            'scope' => $scope,
        ];
    }

    /**
     * Pull the document's text (whole or a single page) for summarize/translate, capped to the
     * configured character budget so a huge document can't blow the token limit.
     *
     * @return array{0: string, 1: string|null}
     */
    protected function gatherText(Document $document, ?int $page): array
    {
        $contents = (string) Storage::disk($document->disk)->get($document->activePath());
        $extract = $this->pdf->extractText(
            $contents,
            (string) config('services.ai.language'),
            $document->original_filename,
        );

        if ($page !== null) {
            $text = '';
            foreach ($extract['pages'] as $pageText) {
                if ((int) $pageText['page_number'] === $page) {
                    $text = (string) $pageText['text'];
                    break;
                }
            }
            $scope = "page {$page}";
        } else {
            $text = (string) $extract['text'];
            $scope = null;
        }

        $text = trim($text);

        if ($text === '') {
            throw new RuntimeException('No readable text was found to process.');
        }

        return [mb_substr($text, 0, (int) config('services.ai.max_input_chars')), $scope];
    }
}
