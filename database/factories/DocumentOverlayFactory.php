<?php

namespace Database\Factories;

use App\Enums\DocumentOverlayType;
use App\Models\Document;
use App\Models\DocumentOverlay;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DocumentOverlay>
 */
class DocumentOverlayFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'document_id' => Document::factory(),
            'page_number' => 1,
            'type' => DocumentOverlayType::Text,
            'payload' => [
                'x' => 72,
                'y' => 700,
                'width' => 200,
                'height' => 24,
                'text' => fake()->sentence(3),
                'font_size' => 12,
                'color' => '#000000',
            ],
            'z_index' => 0,
            'order' => 0,
        ];
    }

    /**
     * A whiteout rectangle that hides existing content.
     */
    public function whiteout(): static
    {
        return $this->state(fn (): array => [
            'type' => DocumentOverlayType::Whiteout,
            'payload' => ['x' => 72, 'y' => 700, 'width' => 120, 'height' => 16, 'color' => '#ffffff'],
        ]);
    }
}
