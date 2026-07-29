<?php

namespace Database\Factories;

use App\Models\Document;
use App\Models\DocumentChunk;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DocumentChunk>
 */
class DocumentChunkFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'document_id' => Document::factory(),
            'page_number' => 1,
            'chunk_index' => 0,
            'content' => fake()->paragraph(),
            'embedding' => null,
            'embedding_model' => 'hash',
            'token_count' => null,
        ];
    }

    /**
     * A chunk carrying an embedding vector (so it is retrievable).
     *
     * @param  list<float>  $vector
     */
    public function withEmbedding(array $vector): static
    {
        return $this->state(fn (array $attributes): array => [
            'embedding' => $vector,
        ]);
    }
}
