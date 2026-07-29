<?php

namespace App\Models;

use App\Enums\DocumentOverlayType;
use Carbon\CarbonImmutable;
use Database\Factories\DocumentOverlayFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One non-destructive overlay edit on a document page. Geometry lives in `payload` in PDF
 * user space (points, bottom-left origin); the Python `/pdf/bake` endpoint flattens overlays
 * onto a copy of the document into a new version. The original file is never modified.
 *
 * @property int $id
 * @property int $document_id
 * @property int $page_number
 * @property DocumentOverlayType $type
 * @property array<string, mixed> $payload
 * @property int $z_index
 * @property int $order
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['document_id', 'page_number', 'type', 'payload', 'z_index', 'order'])]
class DocumentOverlay extends Model
{
    /** @use HasFactory<DocumentOverlayFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'page_number' => 'integer',
            'type' => DocumentOverlayType::class,
            'payload' => 'array',
            'z_index' => 'integer',
            'order' => 'integer',
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
