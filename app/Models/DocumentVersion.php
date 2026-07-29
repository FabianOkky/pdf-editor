<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\DocumentVersionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $document_id
 * @property int $version_number
 * @property string $path
 * @property int $page_count
 * @property int $size_bytes
 * @property string|null $label
 * @property int|null $created_by
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['document_id', 'version_number', 'path', 'page_count', 'size_bytes', 'label', 'created_by'])]
class DocumentVersion extends Model
{
    /** @use HasFactory<DocumentVersionFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'version_number' => 'integer',
            'page_count' => 'integer',
            'size_bytes' => 'integer',
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
     * The user who created this version (nullable).
     *
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
