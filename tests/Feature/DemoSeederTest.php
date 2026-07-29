<?php

use App\Models\Document;
use App\Models\User;
use Database\Seeders\DemoDocumentsSeeder;
use Illuminate\Support\Facades\Storage;

it('seeds a demo library from the committed sample manifest', function () {
    Storage::fake('pdfs');
    $user = User::factory()->create();

    (new DemoDocumentsSeeder)->run($user);

    expect($user->documents()->count())->toBe(3);

    $document = $user->documents()->where('title', 'Welcome to PDF Studio')->first();

    expect($document)->not->toBeNull()
        ->and($document->source_type->value)->toBe('native')
        ->and(data_get($document->meta, 'thumbnail_path'))->not->toBeNull();

    Storage::disk('pdfs')->assertExists($document->path);
    Storage::disk('pdfs')->assertExists(data_get($document->meta, 'thumbnail_path'));
});

it('is idempotent and skips users who already have documents', function () {
    Storage::fake('pdfs');
    $user = User::factory()->create();
    Document::factory()->for($user)->create();

    (new DemoDocumentsSeeder)->run($user);

    expect($user->documents()->count())->toBe(1);
});
