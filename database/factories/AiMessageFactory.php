<?php

namespace Database\Factories;

use App\Enums\AiMessageRole;
use App\Models\AiConversation;
use App\Models\AiMessage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AiMessage>
 */
class AiMessageFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'conversation_id' => AiConversation::factory(),
            'role' => AiMessageRole::User,
            'content' => fake()->paragraph(),
            'tokens' => null,
            'meta' => null,
        ];
    }

    /**
     * An assistant reply (optionally carrying the page numbers it cited).
     *
     * @param  list<int>  $pages
     */
    public function assistant(array $pages = []): static
    {
        return $this->state(fn (array $attributes): array => [
            'role' => AiMessageRole::Assistant,
            'meta' => $pages === [] ? null : ['pages' => $pages],
        ]);
    }
}
