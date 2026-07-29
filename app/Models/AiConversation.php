<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\AiConversationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A "Chat with PDF" conversation, scoped to one document and one user. The thread of turns
 * lives in {@see AiMessage}. AI output stays in the panel — it is never written onto the PDF.
 *
 * @property int $id
 * @property int $document_id
 * @property int $user_id
 * @property string|null $title
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Document $document
 * @property-read User $user
 * @property-read Collection<int, AiMessage> $messages
 */
#[Fillable(['user_id', 'title'])]
class AiConversation extends Model
{
    /** @use HasFactory<AiConversationFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<Document, $this>
     */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The turns of the conversation, oldest first (the order they were sent).
     *
     * @return HasMany<AiMessage, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(AiMessage::class, 'conversation_id')->oldest('id');
    }
}
