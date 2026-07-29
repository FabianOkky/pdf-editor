<?php

use App\Livewire\Documents\Editor;
use App\Models\Document;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('pdfs');
});

it('detects form fields for the owner via the service', function () {
    $user = User::factory()->create();
    $document = Document::factory()->for($user)->create(['path' => 'documents/o.pdf']);
    Storage::disk('pdfs')->put('documents/o.pdf', '%PDF');
    fakePdfFormFields([
        ['name' => 'full_name', 'type' => 'text', 'value' => '', 'page_number' => 1, 'x' => 100, 'y' => 672, 'width' => 200, 'height' => 20, 'options' => [], 'readonly' => false, 'required' => false],
    ]);

    Livewire::actingAs($user)
        ->test(Editor::class, ['document' => $document])
        ->call('detectFormFields')
        ->assertHasNoErrors();

    Http::assertSent(fn ($request) => str_contains($request->url(), '/pdf/form-fields')
        && $request->hasHeader('X-Pdf-Secret'));
});

it('surfaces a friendly error when form detection fails', function () {
    $user = User::factory()->create();
    $document = Document::factory()->for($user)->create(['path' => 'documents/o.pdf']);
    Storage::disk('pdfs')->put('documents/o.pdf', '%PDF');
    Http::fake(['*/pdf/form-fields' => Http::response('boom', 500)]);

    Livewire::actingAs($user)
        ->test(Editor::class, ['document' => $document])
        ->call('detectFormFields')
        ->assertHasErrors('forms');
});

it('persists and bakes a form_field overlay into a new version', function () {
    $user = User::factory()->create();
    $document = Document::factory()->for($user)->create(['path' => 'documents/o.pdf']);
    Storage::disk('pdfs')->put('documents/o.pdf', '%PDF');

    $component = Livewire::actingAs($user)
        ->test(Editor::class, ['document' => $document])
        ->call('syncOverlays', [[
            'type' => 'form_field',
            'page_number' => 1,
            'payload' => [
                'x' => 100, 'y' => 672, 'width' => 200, 'height' => 20,
                'field_name' => 'full_name', 'field_type' => 'text', 'value' => 'Jane Q. Public',
                'font_size' => 12, 'color' => '#111827', 'align' => 'left', 'opacity' => 1,
            ],
            'z_index' => 1,
            'order' => 0,
        ]])
        ->assertHasNoErrors();

    expect($document->overlays()->where('type', 'form_field')->count())->toBe(1);

    fakePdfBake();

    $component->call('bake')->assertHasNoErrors();

    // The field value is committed into a flattened version; the overlay layer is cleared.
    expect($document->versions()->count())->toBe(1)
        ->and($document->overlays()->count())->toBe(0);

    Http::assertSent(function ($request) {
        if (! str_contains($request->url(), '/pdf/bake')) {
            return false;
        }

        $overlays = collect($request->data())->firstWhere('name', 'overlays');

        return is_array($overlays) && str_contains($overlays['contents'], '"type":"form_field"');
    });
});
