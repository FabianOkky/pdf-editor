<?php

namespace Database\Factories;

use App\Enums\ExportFormat;
use App\Enums\ExportJobStatus;
use App\Models\Document;
use App\Models\ExportJob;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ExportJob>
 */
class ExportJobFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'document_id' => Document::factory(),
            'created_by' => null,
            'format' => ExportFormat::Docx,
            'engine' => null,
            'status' => ExportJobStatus::Queued,
            'result_path' => null,
            'result_filename' => null,
            'result_size_bytes' => null,
            'error' => null,
            'meta' => null,
        ];
    }

    /**
     * A finished export with a stored result file ready to download.
     */
    public function completed(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => ExportJobStatus::Completed,
            'engine' => 'pdf2docx',
            'result_path' => 'exports/'.fake()->uuid().'.docx',
            'result_filename' => fake()->slug(2).'.docx',
            'result_size_bytes' => fake()->numberBetween(10_000, 2_000_000),
            'meta' => ['source_type' => 'native', 'ocr_applied' => false, 'page_count' => 1],
        ]);
    }

    /**
     * A failed export carrying an error message.
     */
    public function failed(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => ExportJobStatus::Failed,
            'error' => 'The export could not be completed.',
        ]);
    }
}
