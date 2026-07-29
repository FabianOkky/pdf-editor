<?php

namespace Database\Factories;

use App\Enums\SignatureType;
use App\Models\Signature;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Signature>
 */
class SignatureFactory extends Factory
{
    /** A 1x1 transparent PNG as a data URL — a valid stand-in for a real signature image. */
    private const PNG_DATA_URL = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+M8AAAMBAQAY3Y2wAAAAAElFTkSuQmCC';

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => fake()->firstName(),
            'type' => SignatureType::Draw,
            'data' => self::PNG_DATA_URL,
        ];
    }
}
