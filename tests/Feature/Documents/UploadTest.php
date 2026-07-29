<?php

use App\Enums\DocumentSourceType;
use App\Enums\DocumentStatus;
use App\Livewire\Documents\Index;
use App\Models\Document;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('pdfs');
});

/**
 * Fake the Python service so info()/thumbnails() resolve without a running microservice.
 */
function fakePdfService(int $pageCount = 3, string $sourceType = 'native'): void
{
    Http::fake([
        '*/pdf/info' => Http::response([
            'page_count' => $pageCount,
            'pages' => array_fill(0, max($pageCount, 1), ['width' => 612.0, 'height' => 792.0]),
            'source_type' => $sourceType,
        ]),
        '*/pdf/thumbnails' => Http::response([
            'thumbnails' => [[
                'page' => 1,
                'width' => 120,
                'height' => 160,
                'format' => 'png',
                'image_base64' => base64_encode('fake-png-bytes'),
            ]],
        ]),
    ]);
}

it('uploads a valid PDF, stores it immutably, and creates a ready document', function () {
    $user = User::factory()->create();
    fakePdfService(pageCount: 3, sourceType: 'native');

    Livewire::actingAs($user)
        ->test(Index::class)
        ->set('file', UploadedFile::fake()->create('My Report.pdf', 200, 'application/pdf'))
        ->call('save')
        ->assertHasNoErrors();

    $document = Document::firstOrFail();

    expect($document->user_id)->toBe($user->id)
        ->and($document->title)->toBe('My Report')
        ->and($document->original_filename)->toBe('My Report.pdf')
        ->and($document->page_count)->toBe(3)
        ->and($document->source_type)->toBe(DocumentSourceType::Native)
        ->and($document->status)->toBe(DocumentStatus::Ready)
        ->and($document->disk)->toBe('pdfs');

    Storage::disk('pdfs')->assertExists($document->path);
});

it('stores a cover thumbnail from the service response', function () {
    $user = User::factory()->create();
    fakePdfService();

    Livewire::actingAs($user)
        ->test(Index::class)
        ->set('file', UploadedFile::fake()->create('doc.pdf', 100, 'application/pdf'))
        ->call('save')
        ->assertHasNoErrors();

    $document = Document::firstOrFail();
    $thumbnailPath = data_get($document->meta, 'thumbnail_path');

    expect($thumbnailPath)->not->toBeNull();
    Storage::disk('pdfs')->assertExists($thumbnailPath);
});

it('sends the uploaded file to the Python service for analysis', function () {
    $user = User::factory()->create();
    fakePdfService();

    Livewire::actingAs($user)
        ->test(Index::class)
        ->set('file', UploadedFile::fake()->create('doc.pdf', 100, 'application/pdf'))
        ->call('save')
        ->assertHasNoErrors();

    Http::assertSent(fn ($request) => str_contains($request->url(), '/pdf/info'));
});

it('rejects a non-PDF upload', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(Index::class)
        ->set('file', UploadedFile::fake()->create('notes.txt', 10, 'text/plain'))
        ->call('save')
        ->assertHasErrors('file');

    expect(Document::count())->toBe(0);
});

it('rejects a PDF larger than the configured limit', function () {
    config()->set('services.pdf.max_upload_mb', 1);
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(Index::class)
        ->set('file', UploadedFile::fake()->create('huge.pdf', 2048, 'application/pdf'))
        ->call('save')
        ->assertHasErrors('file');

    expect(Document::count())->toBe(0);
});

it('rejects a PDF that exceeds the page cap and stores nothing', function () {
    config()->set('services.pdf.max_pages', 500);
    $user = User::factory()->create();
    fakePdfService(pageCount: 9999);

    Livewire::actingAs($user)
        ->test(Index::class)
        ->set('file', UploadedFile::fake()->create('book.pdf', 200, 'application/pdf'))
        ->call('save')
        ->assertHasErrors('file');

    expect(Document::count())->toBe(0)
        ->and(Storage::disk('pdfs')->allFiles())->toBeEmpty();
});

it('shows a clear error and creates nothing when the service is unreachable', function () {
    $user = User::factory()->create();
    Http::fake(['*/pdf/info' => Http::response(null, 500)]);

    Livewire::actingAs($user)
        ->test(Index::class)
        ->set('file', UploadedFile::fake()->create('doc.pdf', 100, 'application/pdf'))
        ->call('save')
        ->assertHasErrors('file');

    expect(Document::count())->toBe(0);
});

it('reports the service as unavailable (not a bad PDF) when it cannot be reached', function () {
    $user = User::factory()->create();
    Http::fake(['*/pdf/info' => fn () => throw new ConnectionException('Connection refused')]);

    Livewire::actingAs($user)
        ->test(Index::class)
        ->set('file', UploadedFile::fake()->create('doc.pdf', 100, 'application/pdf'))
        ->call('save')
        ->assertHasErrors(['file' => __('The PDF processing service is unavailable right now. Please try again in a moment.')]);

    expect(Document::count())->toBe(0)
        ->and(Storage::disk('pdfs')->allFiles())->toBeEmpty();
});
