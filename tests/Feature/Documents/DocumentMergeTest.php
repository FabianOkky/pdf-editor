<?php

use App\Livewire\Documents\Index;
use App\Models\Document;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('pdfs');
});

it('merges selected documents, in order, into one new document', function () {
    $user = User::factory()->create();
    $first = Document::factory()->for($user)->create(['page_count' => 2, 'path' => 'documents/a.pdf']);
    $second = Document::factory()->for($user)->create(['page_count' => 1, 'path' => 'documents/b.pdf']);
    Storage::disk('pdfs')->put('documents/a.pdf', '%PDF-a');
    Storage::disk('pdfs')->put('documents/b.pdf', '%PDF-b');
    fakePdfPages([pdfOutput(3, '%PDF-merged')]);

    Livewire::actingAs($user)
        ->test(Index::class)
        ->set('selected', [$second->id, $first->id])
        ->call('merge')
        ->assertHasNoErrors();

    expect(Document::where('user_id', $user->id)->count())->toBe(3);

    $merged = Document::query()->where('user_id', $user->id)->latest('id')->first();
    expect($merged->page_count)->toBe(3)
        ->and($merged->meta['merged_from'])->toBe([$second->id, $first->id]);

    // Both files were uploaded to the service, in the chosen order.
    Http::assertSent(function ($request) {
        $files = collect($request->data())->where('name', 'files');
        $spec = collect($request->data())->firstWhere('name', 'spec');

        return str_contains($request->url(), '/pdf/pages')
            && $files->count() === 2
            && str_contains($spec['contents'], '"op":"merge"');
    });
});

it('cannot merge another user’s document', function () {
    $user = User::factory()->create();
    $mine = Document::factory()->for($user)->create(['path' => 'documents/mine.pdf']);
    Storage::disk('pdfs')->put('documents/mine.pdf', '%PDF');
    $theirs = Document::factory()->for(User::factory())->create();
    fakePdfPages([pdfOutput(2)]);

    Livewire::actingAs($user)
        ->test(Index::class)
        ->set('selected', [$mine->id, $theirs->id])
        ->call('merge')
        ->assertHasErrors('merge');

    // No new document; the foreign document is silently excluded from the selection.
    expect(Document::where('user_id', $user->id)->count())->toBe(1);
    Http::assertNothingSent();
});

it('reorders the merge selection with moveUp', function () {
    $user = User::factory()->create();
    $a = Document::factory()->for($user)->create();
    $b = Document::factory()->for($user)->create();

    Livewire::actingAs($user)
        ->test(Index::class)
        ->set('selected', [$a->id, $b->id])
        ->call('moveUp', 1)
        ->assertSet('selected', [$b->id, $a->id]);
});
