<?php

use App\Enums\DocumentSourceType;
use App\Enums\DocumentStatus;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\User;

it('creates a document via factory with enum casts', function () {
    $document = Document::factory()->create();

    expect($document->source_type)->toBeInstanceOf(DocumentSourceType::class)
        ->and($document->status)->toBe(DocumentStatus::Ready)
        ->and($document->user)->toBeInstanceOf(User::class)
        ->and($document->disk)->toBe('pdfs');
});

it('has many versions and belongs to a user', function () {
    $document = Document::factory()
        ->has(
            DocumentVersion::factory()->count(2)->sequence(
                ['version_number' => 1],
                ['version_number' => 2],
            ),
            'versions',
        )
        ->create();

    expect($document->versions)->toHaveCount(2)
        ->and($document->versions->first())->toBeInstanceOf(DocumentVersion::class)
        ->and($document->versions->first()->document->is($document))->toBeTrue();
});

it('soft deletes documents', function () {
    $document = Document::factory()->create();

    $document->delete();

    expect(Document::count())->toBe(0)
        ->and(Document::withTrashed()->count())->toBe(1);
});

it('marks a document as scanned via factory state', function () {
    $document = Document::factory()->scanned()->create();

    expect($document->source_type)->toBe(DocumentSourceType::Scanned);
});
