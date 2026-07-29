<?php

namespace Database\Factories;

use App\Enums\DocumentSourceType;
use App\Enums\DocumentStatus;
use App\Models\Document;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Document>
 */
class DocumentFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'title' => fake()->sentence(3),
            'original_filename' => fake()->slug(2).'.pdf',
            'disk' => 'pdfs',
            'path' => 'documents/'.fake()->uuid().'.pdf',
            'page_count' => fake()->numberBetween(1, 30),
            'size_bytes' => fake()->numberBetween(10_000, 5_000_000),
            'mime' => 'application/pdf',
            'source_type' => DocumentSourceType::Native,
            'status' => DocumentStatus::Ready,
            'meta' => null,
        ];
    }

    /**
     * A scanned (image-only) document that will need OCR.
     */
    public function scanned(): static
    {
        return $this->state(fn (array $attributes): array => [
            'source_type' => DocumentSourceType::Scanned,
        ]);
    }

    /**
     * A document still being processed by the PDF service.
     */
    public function processing(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => DocumentStatus::Processing,
        ]);
    }
}
