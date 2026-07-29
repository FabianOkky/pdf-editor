<?php

namespace App\Models;

use App\Enums\ExportFormat;
use App\Enums\ExportJobStatus;
use Carbon\CarbonImmutable;
use Database\Factories\ExportJobFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One asynchronous export run for a document (PDF → DOCX). Tracks the lifecycle status and,
 * when finished, where the produced file lives. The source document is never modified — the
 * export is a brand-new artifact (the Golden Rule).
 *
 * @property int $id
 * @property int $document_id
 * @property int|null $created_by
 * @property ExportFormat $format
 * @property string|null $engine
 * @property ExportJobStatus $status
 * @property string|null $result_path
 * @property string|null $result_filename
 * @property int|null $result_size_bytes
 * @property string|null $error
 * @property array<string, mixed>|null $meta
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Document $document
 */
#[Fillable([
    'document_id', 'created_by', 'format', 'engine', 'status',
    'result_path', 'result_filename', 'result_size_bytes', 'error', 'meta',
])]
class ExportJob extends Model
{
    /** @use HasFactory<ExportJobFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'format' => ExportFormat::class,
            'status' => ExportJobStatus::class,
            'result_size_bytes' => 'integer',
            'meta' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Document, $this>
     */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    /**
     * The user who requested this export (nullable).
     *
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Whether the export finished successfully and its file is ready to download.
     */
    public function isDownloadable(): bool
    {
        return $this->status === ExportJobStatus::Completed && $this->result_path !== null;
    }
}
