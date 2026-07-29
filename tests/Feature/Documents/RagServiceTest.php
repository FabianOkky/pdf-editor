<?php

use App\Models\Document;
use App\Models\DocumentChunk;
use App\Services\RagService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('pdfs');
});

it('indexes a document into embedded chunks and records the index fingerprint', function () {
    fakeAi(['*/pdf/extract-text' => fakeExtractText([
        ['page_number' => 1, 'text' => 'Invoice total amount payment'],
    ])]);

    $document = Document::factory()->create(['path' => 'documents/d.pdf']);
    Storage::disk('pdfs')->put('documents/d.pdf', '%PDF');

    $count = app(RagService::class)->index($document);

    expect($count)->toBe(1);

    $chunk = $document->chunks()->first();
    expect($chunk)->not->toBeNull()
        ->and($chunk->page_number)->toBe(1)
        ->and($chunk->embedding)->toBeArray()
        ->and($chunk->embedding_model)->toBe('hash');

    expect(data_get($document->fresh()->meta, 'ai_index.chunk_count'))->toBe(1)
        ->and(data_get($document->fresh()->meta, 'ai_index.signature'))->not->toBeNull();
});

it('retrieves the most similar chunk first by cosine similarity', function () {
    $document = Document::factory()->create();
    DocumentChunk::factory()->for($document)->create(['chunk_index' => 0, 'content' => 'Alpha', 'embedding' => [1.0, 0.0, 0.0]]);
    DocumentChunk::factory()->for($document)->create(['chunk_index' => 1, 'content' => 'Beta', 'embedding' => [0.0, 1.0, 0.0]]);

    // The query embedding leans toward the first chunk.
    fakeAi(['*/ai/embed' => Http::response(['model' => 'hash', 'dimensions' => 3, 'embeddings' => [[0.9, 0.1, 0.0]]])]);

    $results = app(RagService::class)->retrieve($document, 'closest to alpha', 5);

    expect($results)->toHaveCount(2)
        ->and($results[0]['content'])->toBe('Alpha')
        ->and($results[1]['content'])->toBe('Beta')
        ->and($results[0]['score'])->toBeGreaterThan($results[1]['score']);
});

it('skips re-indexing when the document is already current', function () {
    fakeAi(['*/pdf/extract-text' => fakeExtractText([['page_number' => 1, 'text' => 'Some text']])]);

    $document = Document::factory()->create(['path' => 'documents/d.pdf']);
    Storage::disk('pdfs')->put('documents/d.pdf', '%PDF');

    app(RagService::class)->index($document);
    $document->refresh();

    // Reset the HTTP recorder; a true no-op makes no further service calls.
    Http::fake();
    app(RagService::class)->ensureIndexed($document);

    Http::assertNothingSent();
});

it('re-indexes when the active bytes change', function () {
    fakeAi(['*/pdf/extract-text' => fakeExtractText([['page_number' => 1, 'text' => 'Original text']])]);

    $document = Document::factory()->create(['path' => 'documents/d.pdf']);
    Storage::disk('pdfs')->put('documents/d.pdf', '%PDF');

    app(RagService::class)->index($document);

    // Simulate a new active version by changing the path the signature is derived from.
    $document->update(['path' => 'documents/changed.pdf']);
    Storage::disk('pdfs')->put('documents/changed.pdf', '%PDF v2');

    app(RagService::class)->ensureIndexed($document->fresh());

    Http::assertSent(fn ($request) => str_contains($request->url(), '/pdf/extract-text'));
});
