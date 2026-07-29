<?php

namespace App\Models;

use App\Enums\SignatureType;
use Carbon\CarbonImmutable;
use Database\Factories\SignatureFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A reusable signature owned by a user. The image is stored inline as a PNG data URL so it
 * can be embedded directly into a `signature` overlay payload when placed on a document. All
 * three creation methods (draw/type/upload) end up as an image; {@see SignatureType} is just
 * metadata for the saved-signatures list.
 *
 * @property int $id
 * @property int $user_id
 * @property string $name
 * @property SignatureType $type
 * @property string $data
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['user_id', 'name', 'type', 'data'])]
class Signature extends Model
{
    /** @use HasFactory<SignatureFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => SignatureType::class,
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
