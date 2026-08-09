<?php

use App\Enums\DocumentOverlayType;
use App\Jobs\ExportDocumentJob;
use App\Livewire\Documents\Show;
use App\Models\Document;
use App\Models\DocumentOverlay;
use App\Models\DocumentVersion;
use App\Models\ExportJob;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/**
 * The edits-actually-come-out contract.
 *
 * Everything a user takes away from the app — the downloaded PDF, the Word export — reads the
 * document's *bytes*. Edits only reach the bytes once the overlay layer is flattened, so these
 * tests pin the two ways that used to silently hand back an unedited file: downloading after a
 * bake, and exporting with edits still pending.
 */
beforeEach(function () {
    Storage::fake('pdfs');
});

/** A document whose original is on disk, optionally with a later baked version. */
function documentWithBytes(User $user, string $original = '%PDF original'): Document
{
    $document = Document::factory()->for($user)->create([
        'title' => 'Quarterly Report',
        'path' => 'documents/original.pdf',
        'original_filename' => 'upload.pdf',
    ]);
    Storage::disk('pdfs')->put($document->path, $original);

    return $document;
}

it('downloads the edited version, not the original, once edits have been applied', function () {
    $user = User::factory()->create();
    $document = documentWithBytes($user);

    DocumentVersion::factory()->for($document)->create([
        'version_number' => 1,
        'path' => 'documents/versions/v1.pdf',
        'created_by' => $user->id,
    ]);
    Storage::disk('pdfs')->put('documents/versions/v1.pdf', '%PDF edited');

    $response = $this->actingAs($user)->get(route('documents.download', $document));

    $response->assertOk();
    expect($response->streamedContent())->toBe('%PDF edited')
        ->and($response->headers->get('Content-Disposition'))->toContain('Quarterly Report.pdf');
});

it('still offers the untouched original on its own route', function () {
    $user = User::factory()->create();
    $document = documentWithBytes($user);

    DocumentVersion::factory()->for($document)->create([
        'version_number' => 1,
        'path' => 'documents/versions/v1.pdf',
        'created_by' => $user->id,
    ]);
    Storage::disk('pdfs')->put('documents/versions/v1.pdf', '%PDF edited');

    $response = $this->actingAs($user)->get(route('documents.download.original', $document));

    $response->assertOk();
    expect($response->streamedContent())->toBe('%PDF original');
});

it('blocks the original download for a non-owner', function () {
    $document = documentWithBytes(User::factory()->create());

    $this->actingAs(User::factory()->create())
        ->get(route('documents.download.original', $document))
        ->assertForbidden();
});

it('reports how many edits are saved but not yet applied', function () {
    $user = User::factory()->create();
    $document = documentWithBytes($user);
    DocumentOverlay::factory()->for($document)->count(3)->create();

    Livewire::actingAs($user)
        ->test(Show::class, ['document' => $document])
        ->assertSee('3 edits are saved but not applied yet');
});

it('applies pending edits from the viewer, producing a new version', function () {
    fakePdfBake(pageCount: 2, bytes: '%PDF flattened');

    $user = User::factory()->create();
    $document = documentWithBytes($user);
    DocumentOverlay::factory()->for($document)->create([
        'type' => DocumentOverlayType::Text,
        'page_number' => 1,
    ]);

    Livewire::actingAs($user)
        ->test(Show::class, ['document' => $document])
        ->call('applyEditsAndRefresh')
        ->assertHasNoErrors();

    $version = $document->versions()->latest('version_number')->first();

    expect($version)->not->toBeNull()
        ->and(Storage::disk('pdfs')->get($version->path))->toBe('%PDF flattened')
        ->and($document->overlays()->count())->toBe(0)
        // The Golden Rule: flattening writes a copy, the original is byte-identical.
        ->and(Storage::disk('pdfs')->get($document->path))->toBe('%PDF original');
});

it('applies pending edits before queueing a word export', function () {
    Queue::fake();
    fakePdfBake(pageCount: 1, bytes: '%PDF flattened');

    $user = User::factory()->create();
    $document = documentWithBytes($user);
    DocumentOverlay::factory()->for($document)->create(['page_number' => 1]);

    Livewire::actingAs($user)
        ->test(Show::class, ['document' => $document])
        ->call('exportToWord')
        ->assertHasNoErrors();

    // The export runs against the flattened bytes, so the .docx carries the user's edits.
    expect($document->activePath())->not->toBe($document->path)
        ->and(Storage::disk('pdfs')->get($document->activePath()))->toBe('%PDF flattened')
        ->and($document->overlays()->count())->toBe(0);

    Queue::assertPushed(ExportDocumentJob::class);
});

it('does not queue an export when applying the pending edits fails', function () {
    Queue::fake();
    Http::fake(['*/pdf/bake' => Http::response('boom', 500)]);

    $user = User::factory()->create();
    $document = documentWithBytes($user);
    DocumentOverlay::factory()->for($document)->create(['page_number' => 1]);

    Livewire::actingAs($user)
        ->test(Show::class, ['document' => $document])
        ->call('exportToWord')
        ->assertHasErrors('apply');

    expect(ExportJob::where('document_id', $document->id)->count())->toBe(0);
    Queue::assertNothingPushed();
});

it('downloads with pending edits applied first', function () {
    fakePdfBake(pageCount: 1, bytes: '%PDF flattened');

    $user = User::factory()->create();
    $document = documentWithBytes($user);
    DocumentOverlay::factory()->for($document)->create(['page_number' => 1]);

    $response = Livewire::actingAs($user)
        ->test(Show::class, ['document' => $document])
        ->call('downloadEdited')
        ->assertHasNoErrors();

    expect($document->overlays()->count())->toBe(0)
        ->and(Storage::disk('pdfs')->get($document->activePath()))->toBe('%PDF flattened');

    $response->assertFileDownloaded('Quarterly Report.pdf');
});
