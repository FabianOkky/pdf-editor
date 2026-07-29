<?php

use App\Enums\SignatureType;
use App\Livewire\Documents\Editor;
use App\Models\Document;
use App\Models\Signature;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('pdfs');
});

/** A 1x1 transparent PNG data URL — a valid signature image. */
function pngDataUrl(): string
{
    return 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+M8AAAMBAQAY3Y2wAAAAAElFTkSuQmCC';
}

it('saves a reusable signature for the current user', function () {
    $user = User::factory()->create();
    $document = Document::factory()->for($user)->create();

    Livewire::actingAs($user)
        ->test(Editor::class, ['document' => $document])
        ->call('saveSignature', pngDataUrl(), 'type', 'Jane')
        ->assertHasNoErrors();

    $signature = $user->signatures()->sole();
    expect($signature->type)->toBe(SignatureType::Type)
        ->and($signature->name)->toBe('Jane')
        ->and($signature->data)->toBe(pngDataUrl());
});

it('defaults the signature name when none is given', function () {
    $user = User::factory()->create();
    $document = Document::factory()->for($user)->create();

    Livewire::actingAs($user)
        ->test(Editor::class, ['document' => $document])
        ->call('saveSignature', pngDataUrl(), 'upload', null);

    expect($user->signatures()->first()->name)->toBe('Signature');
});

it('rejects a signature that is not an image data URL', function () {
    $user = User::factory()->create();
    $document = Document::factory()->for($user)->create();

    Livewire::actingAs($user)
        ->test(Editor::class, ['document' => $document])
        ->call('saveSignature', 'https://evil.test/x.png', 'draw', null)
        ->assertHasErrors('signature');

    expect($user->signatures()->count())->toBe(0);
});

it('caps the number of saved signatures per user', function () {
    $user = User::factory()->create();
    $document = Document::factory()->for($user)->create();
    Signature::factory()->count(20)->for($user)->create();

    Livewire::actingAs($user)
        ->test(Editor::class, ['document' => $document])
        ->call('saveSignature', pngDataUrl(), 'draw', null)
        ->assertHasErrors('signature');

    expect($user->signatures()->count())->toBe(20);
});

it('deletes only the user’s own signature', function () {
    $user = User::factory()->create();
    $document = Document::factory()->for($user)->create();
    $mine = Signature::factory()->for($user)->create();
    $theirs = Signature::factory()->for(User::factory())->create();

    Livewire::actingAs($user)
        ->test(Editor::class, ['document' => $document])
        ->call('deleteSignature', $mine->id);

    expect(Signature::find($mine->id))->toBeNull();

    // Another user's signature is untouched (owner-scoped no-op).
    Livewire::actingAs($user)
        ->test(Editor::class, ['document' => $document])
        ->call('deleteSignature', $theirs->id);

    expect(Signature::find($theirs->id))->not->toBeNull();
});

it('exposes only the current user’s saved signatures to the editor', function () {
    $user = User::factory()->create();
    $document = Document::factory()->for($user)->create();
    Signature::factory()->count(2)->for($user)->create();
    Signature::factory()->for(User::factory())->create();

    $editor = Livewire::actingAs($user)
        ->test(Editor::class, ['document' => $document])
        ->instance();

    expect($editor->savedSignatures())->toHaveCount(2);
});

it('persists a signature overlay through sync', function () {
    $user = User::factory()->create();
    $document = Document::factory()->for($user)->create();

    Livewire::actingAs($user)
        ->test(Editor::class, ['document' => $document])
        ->call('syncOverlays', [[
            'type' => 'signature',
            'page_number' => 1,
            'payload' => ['x' => 10, 'y' => 10, 'width' => 120, 'height' => 40, 'data' => pngDataUrl(), 'opacity' => 1],
            'z_index' => 1,
            'order' => 0,
        ]])
        ->assertHasNoErrors();

    expect($document->overlays()->where('type', 'signature')->count())->toBe(1);
});
