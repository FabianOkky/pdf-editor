<?php

namespace App\Models;

use App\Enums\DocumentSourceType;
use App\Enums\DocumentStatus;
use Carbon\CarbonImmutable;
use Database\Factories\DocumentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int $id
 * @property int $user_id
 * @property string $title
 * @property string $original_filename
 * @property string $disk
 * @property string $path
 * @property int $page_count
 * @property int $size_bytes
 * @property string $mime
 * @property DocumentSourceType $source_type
 * @property DocumentStatus $status
 * @property array<string, mixed>|null $meta
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property CarbonImmutable|null $deleted_at
 * @property-read DocumentVersion|null $latestVersion
 * @property-read Collection<int, DocumentOverlay> $overlays
 * @property-read Collection<int, ExportJob> $exportJobs
 * @property-read ExportJob|null $latestExportJob
 * @property-read Collection<int, DocumentChunk> $chunks
 * @property-read Collection<int, AiConversation> $aiConversations
 */
#[Fillable([
    'title', 'original_filename', 'disk', 'path', 'page_count',
    'size_bytes', 'mime', 'source_type', 'status', 'meta',
])]
class Document extends Model
{
    /** @use HasFactory<DocumentFactory> */
    use HasFactory, SoftDeletes;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'page_count' => 'integer',
            'size_bytes' => 'integer',
            'source_type' => DocumentSourceType::class,
            'status' => DocumentStatus::class,
            'meta' => 'array',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<DocumentVersion, $this>
     */
    public function versions(): HasMany
    {
        return $this->hasMany(DocumentVersion::class);
    }

    /**
     * The structured overlay edits for this document (the source of truth for edits).
     *
     * @return HasMany<DocumentOverlay, $this>
     */
    public function overlays(): HasMany
    {
        return $this->hasMany(DocumentOverlay::class);
    }

    /**
     * Asynchronous export runs (PDF → DOCX) for this document, newest first.
     *
     * @return HasMany<ExportJob, $this>
     */
    public function exportJobs(): HasMany
    {
        return $this->hasMany(ExportJob::class)->latest('id');
    }

    /**
     * The RAG retrieval chunks indexed from this document's text (AI assistant, Phase 6).
     *
     * @return HasMany<DocumentChunk, $this>
     */
    public function chunks(): HasMany
    {
        return $this->hasMany(DocumentChunk::class);
    }

    /**
     * The AI "Chat with PDF" conversations held against this document.
     *
     * @return HasMany<AiConversation, $this>
     */
    public function aiConversations(): HasMany
    {
        return $this->hasMany(AiConversation::class);
    }

    /**
     * The most recent version (highest version number), if any has been produced.
     *
     * @return HasOne<DocumentVersion, $this>
     */
    public function latestVersion(): HasOne
    {
        return $this->hasOne(DocumentVersion::class)->ofMany('version_number', 'max');
    }

    /**
     * The most recent export job (any status), if any has been requested.
     *
     * @return HasOne<ExportJob, $this>
     */
    public function latestExportJob(): HasOne
    {
        return $this->hasOne(ExportJob::class)->latestOfMany();
    }

    /**
     * Storage path of the bytes currently shown for this document: the latest version if
     * one exists, otherwise the immutable original. The original file is never rewritten.
     */
    public function activePath(): string
    {
        $path = $this->versions()->orderByDesc('version_number')->value('path');

        return is_string($path) ? $path : $this->path;
    }

    /**
     * Page count currently shown: the latest version's, or the original's when untouched.
     */
    public function activePageCount(): int
    {
        $pageCount = $this->versions()->orderByDesc('version_number')->value('page_count');

        return is_int($pageCount) ? $pageCount : $this->page_count;
    }
}
