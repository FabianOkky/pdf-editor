<?php

namespace App\Services;

use App\Models\Document;
use App\Models\DocumentChunk;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * The RAG layer: turns a document into retrievable, embedded chunks and ranks them against a
 * query. Embeddings are produced by the Python service (deterministic local hashing by default,
 * Voyage when configured); they are stored as portable JSON vectors and ranked **in-process by
 * cosine similarity** (the dev/CI Postgres has no pgvector). Documents are owner-scoped and
 * bounded, so cosine over one document's chunks is cheap. See the document_chunks migration for
 * the pgvector upgrade path.
 */
class RagService
{
    public function __construct(
        protected PdfServiceClient $pdf,
        protected ChunkingService $chunker,
    ) {}

    /**
     * Index the document if it has not been indexed, or if its active bytes have changed since
     * (a bake/restore produces a new active version). A no-op when already current.
     */
    public function ensureIndexed(Document $document): void
    {
        $signature = $this->signatureFor($document);

        if ($document->chunks()->exists() && data_get($document->meta, 'ai_index.signature') === $signature) {
            return;
        }

        $this->index($document, $signature);
    }

    /**
     * (Re)build the document's chunk index: extract text, chunk it, embed the chunks, and store
     * them (replacing any previous index) along with an index fingerprint on the document.
     *
     * @return int the number of chunks indexed
     */
    public function index(Document $document, ?string $signature = null): int
    {
        $signature ??= $this->signatureFor($document);

        $contents = (string) Storage::disk($document->disk)->get($document->activePath());
        $extract = $this->pdf->extractText(
            $contents,
            (string) config('services.ai.language'),
            $document->original_filename,
        );

        $chunks = $this->chunker->chunk(
            $extract['pages'],
            (int) config('services.ai.chunk_size'),
            (int) config('services.ai.chunk_overlap'),
        );

        $model = null;

        if ($chunks !== []) {
            $embedding = $this->pdf->embed(array_column($chunks, 'content'));
            $model = $embedding['model'];

            foreach ($chunks as $i => &$chunk) {
                $chunk['embedding'] = $embedding['embeddings'][$i] ?? null;
                $chunk['embedding_model'] = $model;
                $chunk['token_count'] = null;
            }
            unset($chunk);
        }

        DB::transaction(function () use ($document, $chunks, $signature, $extract, $model): void {
            $document->chunks()->delete();

            if ($chunks !== []) {
                $document->chunks()->createMany($chunks);
            }

            $document->update([
                'meta' => array_merge($document->meta ?? [], [
                    'ai_index' => [
                        'signature' => $signature,
                        'indexed_at' => now()->toIso8601String(),
                        'chunk_count' => count($chunks),
                        'source_type' => $extract['source_type'],
                        'ocr_applied' => $extract['ocr_applied'],
                        'embedding_model' => $model,
                    ],
                ]),
            ]);
        });

        return count($chunks);
    }

    /**
     * Retrieve the chunks most relevant to ``$query``, best first. Returns plain arrays the chat
     * call can use as grounding context (each ``{page_number, content, score}``).
     *
     * @return list<array{page_number: int, content: string, score: float}>
     */
    public function retrieve(Document $document, string $query, ?int $topK = null): array
    {
        $topK ??= (int) config('services.ai.retrieval_top_k');

        $chunks = $document->chunks()->whereNotNull('embedding')->get();

        if ($chunks->isEmpty()) {
            return [];
        }

        $queryVector = $this->pdf->embed([$query])['embeddings'][0] ?? [];

        if ($queryVector === []) {
            return [];
        }

        return array_values($chunks
            ->map(fn (DocumentChunk $chunk): array => [
                'page_number' => $chunk->page_number,
                'content' => $chunk->content,
                'score' => $this->cosine($queryVector, $chunk->embedding ?? []),
            ])
            ->sortByDesc('score')
            ->take(max(1, $topK))
            ->values()
            ->all());
    }

    /**
     * Cosine similarity of two equal-length-ish vectors (compares the shared leading dimensions).
     *
     * @param  list<float>  $a
     * @param  list<float>  $b
     */
    protected function cosine(array $a, array $b): float
    {
        $length = min(count($a), count($b));
        $dot = 0.0;
        $normA = 0.0;
        $normB = 0.0;

        for ($i = 0; $i < $length; $i++) {
            $dot += $a[$i] * $b[$i];
            $normA += $a[$i] * $a[$i];
            $normB += $b[$i] * $b[$i];
        }

        if ($normA <= 0.0 || $normB <= 0.0) {
            return 0.0;
        }

        return $dot / (sqrt($normA) * sqrt($normB));
    }

    /**
     * A fingerprint of the document's current active bytes, used to detect when a re-index is
     * needed (the active path changes whenever a new version is baked or restored).
     */
    protected function signatureFor(Document $document): string
    {
        return sha1($document->activePath());
    }
}
