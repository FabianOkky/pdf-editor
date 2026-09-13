<?php

use App\Models\Document;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoDocumentsSeeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

it('seeds a demo library from the committed sample manifest', function () {
    Storage::fake('pdfs');
    $user = User::factory()->create();

    (new DemoDocumentsSeeder)->run($user);

    expect($user->documents()->count())->toBe(3);

    $document = $user->documents()->where('title', 'Welcome to Lapis')->first();

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

it('seeds the demo account with a working password and a starter library', function () {
    Storage::fake('pdfs');

    $this->seed(DatabaseSeeder::class);

    $demo = User::where('email', DatabaseSeeder::DEMO_EMAIL)->first();

    expect($demo)->not->toBeNull()
        ->and($demo->name)->toBe('Fabian Okky')
        ->and(Hash::check(DatabaseSeeder::DEMO_PASSWORD, $demo->password))->toBeTrue()
        ->and($demo->documents()->count())->toBe(3);
});

it('can be re-run without duplicating the demo account', function () {
    Storage::fake('pdfs');

    $this->seed(DatabaseSeeder::class);
    $this->seed(DatabaseSeeder::class);

    $demo = User::where('email', DatabaseSeeder::DEMO_EMAIL);

    expect($demo->count())->toBe(1)
        ->and($demo->first()->documents()->count())->toBe(3);
});
