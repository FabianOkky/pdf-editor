<?php

use App\Enums\DocumentOverlayType;
use App\Livewire\Documents\Editor;
use App\Models\Document;
use App\Models\DocumentOverlay;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('pdfs');
});

/**
 * @return array<string, mixed>
 */
function textOverlay(int $page = 1): array
{
    return [
        'type' => 'text',
        'page_number' => $page,
        'payload' => ['x' => 72, 'y' => 700, 'width' => 180, 'height' => 22, 'text' => 'Hi', 'font_size' => 14, 'color' => '#111827'],
        'z_index' => 1,
        'order' => 0,
    ];
}

it('opens the editor for the owner', function () {
    $user = User::factory()->create();
    $document = Document::factory()->for($user)->create();

    $this->actingAs($user)
        ->get(route('documents.editor', $document))
        ->assertOk()
        ->assertSeeLivewire(Editor::class);
});

it('forbids editing another user’s document', function () {
    $user = User::factory()->create();
    $document = Document::factory()->for(User::factory())->create();

    $this->actingAs($user)
        ->get(route('documents.editor', $document))
        ->assertForbidden();
});

it('redirects guests from the editor to login', function () {
    $document = Document::factory()->create();

    $this->get(route('documents.editor', $document))->assertRedirect(route('login'));
});

it('persists the overlay layer on sync', function () {
    $user = User::factory()->create();
    $document = Document::factory()->for($user)->create(['page_count' => 3]);

    Livewire::actingAs($user)
        ->test(Editor::class, ['document' => $document])
        ->call('syncOverlays', [
            textOverlay(),
            ['type' => 'whiteout', 'page_number' => 2, 'payload' => ['x' => 10, 'y' => 10, 'width' => 50, 'height' => 20, 'color' => '#ffffff'], 'z_index' => 2, 'order' => 1],
        ])
        ->assertHasNoErrors();

    expect($document->overlays()->count())->toBe(2);
    $first = $document->overlays()->orderBy('order')->first();
    expect($first->type)->toBe(DocumentOverlayType::Text)
        ->and($first->page_number)->toBe(1)
        ->and($first->payload['text'])->toBe('Hi');
});

it('replaces the whole overlay layer on each sync', function () {
    $user = User::factory()->create();
    $document = Document::factory()->for($user)->create();
    DocumentOverlay::factory()->count(3)->for($document)->create();

    Livewire::actingAs($user)
        ->test(Editor::class, ['document' => $document])
        ->call('syncOverlays', [textOverlay()]);

    expect($document->overlays()->count())->toBe(1);
});

it('clears the overlay layer when an empty set is synced', function () {
    $user = User::factory()->create();
    $document = Document::factory()->for($user)->create();
    DocumentOverlay::factory()->count(2)->for($document)->create();

    Livewire::actingAs($user)
        ->test(Editor::class, ['document' => $document])
        ->call('syncOverlays', []);

    expect($document->overlays()->count())->toBe(0);
});

it('drops overlays with an unknown type or out-of-range page', function () {
    $user = User::factory()->create();
    $document = Document::factory()->for($user)->create(['page_count' => 2]);

    Livewire::actingAs($user)
        ->test(Editor::class, ['document' => $document])
        ->call('syncOverlays', [
            ['type' => 'bogus', 'page_number' => 1, 'payload' => ['x' => 1, 'y' => 1, 'width' => 1, 'height' => 1]],
            ['type' => 'whiteout', 'page_number' => 99, 'payload' => ['x' => 1, 'y' => 1, 'width' => 1, 'height' => 1]],
            textOverlay(),
        ]);

    expect($document->overlays()->count())->toBe(1)
        ->and($document->overlays()->first()->type)->toBe(DocumentOverlayType::Text);
});

it('uses the active version page count when validating overlays', function () {
    $user = User::factory()->create();
    $document = Document::factory()->for($user)->create(['page_count' => 1]);
    // A later version added more pages; an overlay on page 2 should now be valid.
    $document->versions()->create([
        'version_number' => 1,
        'path' => 'documents/versions/v1.pdf',
        'page_count' => 2,
        'size_bytes' => 100,
        'label' => 'test',
    ]);

    Livewire::actingAs($user)
        ->test(Editor::class, ['document' => $document])
        ->call('syncOverlays', [textOverlay(page: 2)]);

    expect($document->overlays()->count())->toBe(1);
});
