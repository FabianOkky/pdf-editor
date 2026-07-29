<?php

namespace App\Models;

use App\Enums\AiMessageRole;
use Carbon\CarbonImmutable;
use Database\Factories\AiMessageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One turn in an {@see AiConversation}. ``meta`` holds assistant grounding — the page numbers
 * the answer cited — so the UI can show "Sources" beneath a reply.
 *
 * @property int $id
 * @property int $conversation_id
 * @property AiMessageRole $role
 * @property string $content
 * @property int|null $tokens
 * @property array<string, mixed>|null $meta
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read AiConversation $conversation
 */
#[Fillable(['role', 'content', 'tokens', 'meta'])]
class AiMessage extends Model
{
    /** @use HasFactory<AiMessageFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'role' => AiMessageRole::class,
            'tokens' => 'integer',
            'meta' => 'array',
        ];
    }

    /**
     * @return BelongsTo<AiConversation, $this>
     */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(AiConversation::class, 'conversation_id');
    }
}
