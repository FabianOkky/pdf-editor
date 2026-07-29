<?php

namespace Database\Factories;

use App\Models\Document;
use App\Models\DocumentVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DocumentVersion>
 */
class DocumentVersionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'document_id' => Document::factory(),
            'version_number' => 1,
            'path' => 'documents/versions/'.fake()->uuid().'.pdf',
            'page_count' => fake()->numberBetween(1, 30),
            'size_bytes' => fake()->numberBetween(10_000, 5_000_000),
            'label' => fake()->optional()->sentence(2),
            'created_by' => null,
        ];
    }
}
