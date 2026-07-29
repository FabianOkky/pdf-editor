<?php

use App\Livewire\Documents\Show;
use App\Models\Document;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('pdfs');
});

it('opens the viewer for the owner', function () {
    $user = User::factory()->create();
    $document = Document::factory()->for($user)->create(['title' => 'Viewable doc']);

    $this->actingAs($user)
        ->get(route('documents.show', $document))
        ->assertOk()
        ->assertSeeLivewire(Show::class)
        ->assertSee('Viewable doc');
});

it('forbids opening another user’s document', function () {
    $user = User::factory()->create();
    $document = Document::factory()->for(User::factory())->create();

    $this->actingAs($user)
        ->get(route('documents.show', $document))
        ->assertForbidden();
});

it('redirects guests from the viewer to login', function () {
    $document = Document::factory()->create();

    $this->get(route('documents.show', $document))->assertRedirect(route('login'));
});

it('streams the original PDF inline to the owner', function () {
    $user = User::factory()->create();
    $document = Document::factory()->for($user)->create(['path' => 'documents/sample.pdf']);
    Storage::disk('pdfs')->put($document->path, '%PDF-1.7 fake');

    $this->actingAs($user)
        ->get(route('documents.file', $document))
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf');
});

it('forbids streaming another user’s file', function () {
    $user = User::factory()->create();
    $document = Document::factory()->for(User::factory())->create(['path' => 'documents/sample.pdf']);
    Storage::disk('pdfs')->put($document->path, '%PDF-1.7 fake');

    $this->actingAs($user)
        ->get(route('documents.file', $document))
        ->assertForbidden();
});

it('downloads the original as an attachment for the owner', function () {
    $user = User::factory()->create();
    $document = Document::factory()->for($user)->create([
        'path' => 'documents/sample.pdf',
        'original_filename' => 'sample.pdf',
    ]);
    Storage::disk('pdfs')->put($document->path, '%PDF-1.7 fake');

    $response = $this->actingAs($user)->get(route('documents.download', $document));

    $response->assertOk();
    expect($response->headers->get('Content-Disposition'))->toContain('attachment');
});

it('serves the cover thumbnail for the owner', function () {
    $user = User::factory()->create();
    $document = Document::factory()->for($user)->create([
        'meta' => ['thumbnail_path' => 'thumbnails/cover.png'],
    ]);
    Storage::disk('pdfs')->put('thumbnails/cover.png', 'fake-png');

    $this->actingAs($user)
        ->get(route('documents.thumbnail', $document))
        ->assertOk()
        ->assertHeader('Content-Type', 'image/png');
});

it('returns 404 when a document has no thumbnail', function () {
    $user = User::factory()->create();
    $document = Document::factory()->for($user)->create(['meta' => null]);

    $this->actingAs($user)
        ->get(route('documents.thumbnail', $document))
        ->assertNotFound();
});
