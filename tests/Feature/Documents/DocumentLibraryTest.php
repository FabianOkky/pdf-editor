<?php

use App\Livewire\Documents\Index;
use App\Models\Document;
use App\Models\User;
use Livewire\Livewire;

it('shows only the current user’s documents', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();

    Document::factory()->for($user)->create(['title' => 'My Quarterly Report']);
    Document::factory()->for($other)->create(['title' => 'Someone Elses Secret']);

    Livewire::actingAs($user)
        ->test(Index::class)
        ->assertSee('My Quarterly Report')
        ->assertDontSee('Someone Elses Secret');
});

it('lets an owner rename their document', function () {
    $user = User::factory()->create();
    $document = Document::factory()->for($user)->create(['title' => 'Old title']);

    Livewire::actingAs($user)
        ->test(Index::class)
        ->call('startRename', $document->id)
        ->set('renameTitle', 'New title')
        ->call('rename')
        ->assertHasNoErrors();

    expect($document->refresh()->title)->toBe('New title');
});

it('requires a title when renaming', function () {
    $user = User::factory()->create();
    $document = Document::factory()->for($user)->create();

    Livewire::actingAs($user)
        ->test(Index::class)
        ->call('startRename', $document->id)
        ->set('renameTitle', '')
        ->call('rename')
        ->assertHasErrors('renameTitle');
});

it('trims a renamed document title', function () {
    $user = User::factory()->create();
    $document = Document::factory()->for($user)->create();

    Livewire::actingAs($user)
        ->test(Index::class)
        ->call('startRename', $document->id)
        ->set('renameTitle', '  Final report  ')
        ->call('rename')
        ->assertHasNoErrors();

    expect($document->refresh()->title)->toBe('Final report');
});

it('forbids renaming another user’s document', function () {
    $user = User::factory()->create();
    $document = Document::factory()->for(User::factory())->create();

    Livewire::actingAs($user)
        ->test(Index::class)
        ->call('startRename', $document->id)
        ->assertForbidden();
});

it('lets an owner soft-delete their document', function () {
    $user = User::factory()->create();
    $document = Document::factory()->for($user)->create();

    Livewire::actingAs($user)
        ->test(Index::class)
        ->call('delete', $document->id);

    expect(Document::count())->toBe(0)
        ->and(Document::withTrashed()->count())->toBe(1);
});

it('lets an owner restore a document from trash', function () {
    $user = User::factory()->create();
    $document = Document::factory()->for($user)->create();
    $document->delete();

    Livewire::actingAs($user)
        ->test(Index::class)
        ->assertSee($document->title)
        ->call('restore', $document->id)
        ->assertHasNoErrors();

    expect($document->fresh())->not->toBeNull()
        ->and($document->fresh()->trashed())->toBeFalse();
});

it('does not expose another user’s trash', function () {
    $user = User::factory()->create();
    $document = Document::factory()->for(User::factory())->create(['title' => 'Private deleted file']);
    $document->delete();

    Livewire::actingAs($user)
        ->test(Index::class)
        ->assertDontSee('Private deleted file')
        ->call('restore', $document->id)
        ->assertHasNoErrors();

    expect(Document::withTrashed()->findOrFail($document->id)->trashed())->toBeTrue();
});

it('forbids deleting another user’s document', function () {
    $user = User::factory()->create();
    $document = Document::factory()->for(User::factory())->create();

    Livewire::actingAs($user)
        ->test(Index::class)
        ->call('delete', $document->id)
        ->assertForbidden();

    expect(Document::count())->toBe(1);
});

it('renders the library page for an authenticated user', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('documents.index'))
        ->assertOk()
        ->assertSeeLivewire(Index::class);
});

it('redirects guests away from the library', function () {
    $this->get(route('documents.index'))->assertRedirect(route('login'));
});
