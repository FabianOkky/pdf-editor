<?php

use App\Livewire\Documents\Editor;
use App\Models\Document;
use App\Models\DocumentOverlay;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('pdfs');
});

it('bakes the overlays into a new version and keeps the original intact', function () {
    $user = User::factory()->create();
    $document = Document::factory()->for($user)->create([
        'page_count' => 2,
        'path' => 'documents/original.pdf',
    ]);
    Storage::disk('pdfs')->put('documents/original.pdf', '%PDF-original-bytes');
    DocumentOverlay::factory()->count(2)->for($document)->create();
    fakePdfBake(pageCount: 2, bytes: '%PDF-baked-bytes');

    Livewire::actingAs($user)
        ->test(Editor::class, ['document' => $document])
        ->call('bake')
        ->assertHasNoErrors()
        ->assertRedirect(route('documents.show', $document));

    $version = $document->versions()->firstOrFail();

    expect($version->version_number)->toBe(1)
        ->and($version->page_count)->toBe(2)
        ->and($version->created_by)->toBe($user->id)
        // Overlays are committed into the baked version, so the layer is cleared.
        ->and($document->overlays()->count())->toBe(0);

    Storage::disk('pdfs')->assertExists($version->path);
    // The immutable original is untouched on disk (the Golden Rule).
    expect(Storage::disk('pdfs')->get('documents/original.pdf'))->toBe('%PDF-original-bytes');

    Http::assertSent(fn ($request) => str_contains($request->url(), '/pdf/bake'));
});

it('sends the persisted overlays to the bake endpoint', function () {
    $user = User::factory()->create();
    $document = Document::factory()->for($user)->create(['path' => 'documents/o.pdf']);
    Storage::disk('pdfs')->put('documents/o.pdf', '%PDF');
    DocumentOverlay::factory()->whiteout()->for($document)->create();
    fakePdfBake();

    Livewire::actingAs($user)
        ->test(Editor::class, ['document' => $document])
        ->call('bake');

    Http::assertSent(function ($request) {
        if (! str_contains($request->url(), '/pdf/bake')) {
            return false;
        }

        $overlays = collect($request->data())->firstWhere('name', 'overlays');

        return is_array($overlays) && str_contains($overlays['contents'], '"type":"whiteout"');
    });
});

it('refuses to bake when there are no overlays', function () {
    $user = User::factory()->create();
    $document = Document::factory()->for($user)->create(['path' => 'documents/o.pdf']);
    Storage::disk('pdfs')->put('documents/o.pdf', '%PDF');
    fakePdfBake();

    Livewire::actingAs($user)
        ->test(Editor::class, ['document' => $document])
        ->call('bake')
        ->assertHasErrors('bake');

    expect($document->versions()->count())->toBe(0);
    Http::assertNothingSent();
});

it('surfaces a friendly error when the bake service fails', function () {
    $user = User::factory()->create();
    $document = Document::factory()->for($user)->create(['path' => 'documents/o.pdf']);
    Storage::disk('pdfs')->put('documents/o.pdf', '%PDF');
    DocumentOverlay::factory()->for($document)->create();
    Http::fake(['*/pdf/bake' => Http::response('boom', 500)]);

    Livewire::actingAs($user)
        ->test(Editor::class, ['document' => $document])
        ->call('bake')
        ->assertHasErrors('bake');

    // The edits are preserved so the user can retry.
    expect($document->versions()->count())->toBe(0)
        ->and($document->overlays()->count())->toBe(1);
});
