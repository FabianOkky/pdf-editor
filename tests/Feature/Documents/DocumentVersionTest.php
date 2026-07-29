<?php

use App\Livewire\Documents\Show;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('pdfs');
});

it('restores an older version by appending a copy as the new latest version', function () {
    $user = User::factory()->create();
    $document = Document::factory()->for($user)->create();
    $version1 = DocumentVersion::factory()->for($document)->create([
        'version_number' => 1,
        'path' => 'documents/versions/v1.pdf',
        'page_count' => 2,
    ]);
    DocumentVersion::factory()->for($document)->create([
        'version_number' => 2,
        'path' => 'documents/versions/v2.pdf',
        'page_count' => 5,
    ]);
    Storage::disk('pdfs')->put('documents/versions/v1.pdf', '%PDF-v1-bytes');

    Livewire::actingAs($user)
        ->test(Show::class, ['document' => $document])
        ->call('restoreVersion', $version1->id)
        ->assertHasNoErrors()
        ->assertRedirect(route('documents.show', $document));

    $latest = $document->versions()->latest('version_number')->first();

    expect($latest->version_number)->toBe(3)
        ->and($latest->page_count)->toBe(2)
        ->and(Storage::disk('pdfs')->get($latest->path))->toBe('%PDF-v1-bytes');
});

it('streams a version inline to the owner', function () {
    $user = User::factory()->create();
    $document = Document::factory()->for($user)->create();
    $version = DocumentVersion::factory()->for($document)->create(['path' => 'documents/versions/v.pdf']);
    Storage::disk('pdfs')->put('documents/versions/v.pdf', '%PDF-version');

    $this->actingAs($user)
        ->get(route('documents.versions.file', [$document, $version]))
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf');
});

it('forbids streaming another user’s version', function () {
    $user = User::factory()->create();
    $document = Document::factory()->for(User::factory())->create();
    $version = DocumentVersion::factory()->for($document)->create(['path' => 'documents/versions/v.pdf']);
    Storage::disk('pdfs')->put('documents/versions/v.pdf', '%PDF');

    $this->actingAs($user)
        ->get(route('documents.versions.file', [$document, $version]))
        ->assertForbidden();
});

it('404s when the version belongs to a different document', function () {
    $user = User::factory()->create();
    $documentA = Document::factory()->for($user)->create();
    $documentB = Document::factory()->for($user)->create();
    $versionOfB = DocumentVersion::factory()->for($documentB)->create(['path' => 'documents/versions/v.pdf']);
    Storage::disk('pdfs')->put('documents/versions/v.pdf', '%PDF');

    // Scoped bindings reject a version that does not belong to {document}.
    $this->actingAs($user)
        ->get(route('documents.versions.file', [$documentA, $versionOfB]))
        ->assertNotFound();
});

it('downloads a version as an attachment for the owner', function () {
    $user = User::factory()->create();
    $document = Document::factory()->for($user)->create(['title' => 'Report']);
    $version = DocumentVersion::factory()->for($document)->create([
        'version_number' => 2,
        'path' => 'documents/versions/v.pdf',
    ]);
    Storage::disk('pdfs')->put('documents/versions/v.pdf', '%PDF');

    $response = $this->actingAs($user)->get(route('documents.versions.download', [$document, $version]));

    $response->assertOk();
    expect($response->headers->get('Content-Disposition'))->toContain('attachment');
});

it('viewer loads the latest version when one exists', function () {
    $user = User::factory()->create();
    $document = Document::factory()->for($user)->create(['page_count' => 4]);
    $version = DocumentVersion::factory()->for($document)->create([
        'version_number' => 1,
        'page_count' => 2,
    ]);

    $this->actingAs($user)
        ->get(route('documents.show', $document))
        ->assertOk()
        ->assertSee(route('documents.versions.file', [$document, $version]), escape: false);
});
