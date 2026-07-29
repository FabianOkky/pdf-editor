<?php

use App\Livewire\Documents\Organize;
use App\Models\Document;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('pdfs');
});

it('opens the page manager for the owner', function () {
    $user = User::factory()->create();
    $document = Document::factory()->for($user)->create();

    $this->actingAs($user)
        ->get(route('documents.organize', $document))
        ->assertOk()
        ->assertSeeLivewire(Organize::class);
});

it('forbids organizing another user’s document', function () {
    $user = User::factory()->create();
    $document = Document::factory()->for(User::factory())->create();

    $this->actingAs($user)
        ->get(route('documents.organize', $document))
        ->assertForbidden();
});

it('redirects guests from the page manager to login', function () {
    $document = Document::factory()->create();

    $this->get(route('documents.organize', $document))->assertRedirect(route('login'));
});

it('saves reorganized pages as a new version and keeps the original intact', function () {
    $user = User::factory()->create();
    $document = Document::factory()->for($user)->create([
        'page_count' => 3,
        'path' => 'documents/original.pdf',
    ]);
    Storage::disk('pdfs')->put('documents/original.pdf', '%PDF-original-bytes');
    fakePdfPages([pdfOutput(pageCount: 2, bytes: '%PDF-reorganized')]);

    Livewire::actingAs($user)
        ->test(Organize::class, ['document' => $document])
        ->call('save', [['source' => 3, 'rotate' => 90], ['source' => 1, 'rotate' => 0]])
        ->assertHasNoErrors()
        ->assertRedirect(route('documents.show', $document));

    $version = $document->versions()->firstOrFail();

    expect($version->version_number)->toBe(1)
        ->and($version->page_count)->toBe(2)
        ->and($version->created_by)->toBe($user->id)
        ->and($document->fresh()->page_count)->toBe(3);

    Storage::disk('pdfs')->assertExists($version->path);
    // The immutable original is untouched on disk.
    expect(Storage::disk('pdfs')->get('documents/original.pdf'))->toBe('%PDF-original-bytes');

    Http::assertSent(fn ($request) => str_contains($request->url(), '/pdf/pages'));
});

it('rejects saving an empty page list', function () {
    $user = User::factory()->create();
    $document = Document::factory()->for($user)->create(['page_count' => 2, 'path' => 'documents/o.pdf']);
    Storage::disk('pdfs')->put('documents/o.pdf', '%PDF');
    fakePdfPages([pdfOutput(1)]);

    Livewire::actingAs($user)
        ->test(Organize::class, ['document' => $document])
        ->call('save', [])
        ->assertHasErrors('pages');

    expect($document->versions()->count())->toBe(0);
    Http::assertNothingSent();
});

it('drops out-of-range pages before calling the service', function () {
    $user = User::factory()->create();
    $document = Document::factory()->for($user)->create(['page_count' => 2, 'path' => 'documents/o.pdf']);
    Storage::disk('pdfs')->put('documents/o.pdf', '%PDF');
    fakePdfPages([pdfOutput(1)]);

    Livewire::actingAs($user)
        ->test(Organize::class, ['document' => $document])
        ->call('save', [['source' => 99, 'rotate' => 0], ['source' => 1, 'rotate' => 0]])
        ->assertHasNoErrors();

    // Only the in-range page survived sanitization and was sent to the service.
    Http::assertSent(function ($request) {
        $spec = collect($request->data())->firstWhere('name', 'spec');

        return str_contains($spec['contents'], '"source":1')
            && ! str_contains($spec['contents'], '"source":99');
    });
});
