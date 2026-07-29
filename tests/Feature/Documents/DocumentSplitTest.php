<?php

use App\Livewire\Documents\Show;
use App\Models\Document;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('pdfs');
});

it('splits a document into new documents by page ranges', function () {
    $user = User::factory()->create();
    $document = Document::factory()->for($user)->create([
        'title' => 'Report',
        'page_count' => 5,
        'path' => 'documents/report.pdf',
    ]);
    Storage::disk('pdfs')->put('documents/report.pdf', '%PDF-report');
    fakePdfPages([pdfOutput(2, '%PDF-a'), pdfOutput(3, '%PDF-b')]);

    Livewire::actingAs($user)
        ->test(Show::class, ['document' => $document])
        ->set('splitMode', 'ranges')
        ->set('splitRanges', '1-2, 3-5')
        ->call('split')
        ->assertHasNoErrors()
        ->assertRedirect(route('documents.index'));

    $parts = Document::query()->where('user_id', $user->id)->whereKeyNot($document->id)->get();

    expect(Document::where('user_id', $user->id)->count())->toBe(3)
        ->and($parts->pluck('page_count')->all())->toBe([2, 3])
        ->and($parts->pluck('title')->all())->toBe(['Report (part 1)', 'Report (part 2)']);

    // The original document and its file are untouched.
    expect($document->fresh()->page_count)->toBe(5);
    Storage::disk('pdfs')->assertExists('documents/report.pdf');
});

it('splits every N pages', function () {
    $user = User::factory()->create();
    $document = Document::factory()->for($user)->create(['page_count' => 5, 'path' => 'documents/d.pdf']);
    Storage::disk('pdfs')->put('documents/d.pdf', '%PDF');
    fakePdfPages([pdfOutput(2), pdfOutput(2), pdfOutput(1)]);

    Livewire::actingAs($user)
        ->test(Show::class, ['document' => $document])
        ->set('splitMode', 'every')
        ->set('splitEvery', 2)
        ->call('split')
        ->assertHasNoErrors();

    expect(Document::where('user_id', $user->id)->whereKeyNot($document->id)->count())->toBe(3);

    Http::assertSent(function ($request) {
        if (! str_contains($request->url(), '/pdf/pages')) {
            return false;
        }

        $spec = collect($request->data())->firstWhere('name', 'spec');

        return str_contains($spec['contents'], '"op":"split"')
            && str_contains($spec['contents'], '"every":2');
    });
});

it('rejects an invalid range and creates nothing', function () {
    $user = User::factory()->create();
    $document = Document::factory()->for($user)->create(['page_count' => 3, 'path' => 'documents/d.pdf']);
    Storage::disk('pdfs')->put('documents/d.pdf', '%PDF');
    fakePdfPages([pdfOutput(1)]);

    Livewire::actingAs($user)
        ->test(Show::class, ['document' => $document])
        ->set('splitMode', 'ranges')
        ->set('splitRanges', '1-9')
        ->call('split')
        ->assertHasErrors('split');

    expect(Document::where('user_id', $user->id)->count())->toBe(1);
    Http::assertNothingSent();
});

it('rejects a chunk size that would not split the document', function () {
    $user = User::factory()->create();
    $document = Document::factory()->for($user)->create(['page_count' => 4, 'path' => 'documents/d.pdf']);
    Storage::disk('pdfs')->put('documents/d.pdf', '%PDF');

    Livewire::actingAs($user)
        ->test(Show::class, ['document' => $document])
        ->set('splitMode', 'every')
        ->set('splitEvery', 4)
        ->call('split')
        ->assertHasErrors('split');

    expect(Document::where('user_id', $user->id)->count())->toBe(1);
});
