<?php

namespace App\Models;

use App\Services\RagService;
use Carbon\CarbonImmutable;
use Database\Factories\DocumentChunkFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A retrieval chunk for RAG: a slice of a document's text, tagged with the page it came from,
 * plus its embedding. The embedding is a portable JSON array of floats (the dev/CI Postgres has
 * no pgvector extension); {@see RagService} ranks chunks by cosine in-process.
 *
 * @property int $id
 * @property int $document_id
 * @property int $page_number
 * @property int $chunk_index
 * @property string $content
 * @property list<float>|null $embedding
 * @property string|null $embedding_model
 * @property int|null $token_count
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Document $document
 */
#[Fillable(['page_number', 'chunk_index', 'content', 'embedding', 'embedding_model', 'token_count'])]
class DocumentChunk extends Model
{
    /** @use HasFactory<DocumentChunkFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'page_number' => 'integer',
            'chunk_index' => 'integer',
            'embedding' => 'array',
            'token_count' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Document, $this>
     */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }
}
