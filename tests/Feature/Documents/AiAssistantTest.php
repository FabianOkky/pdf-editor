<?php

use App\Enums\AiMessageRole;
use App\Livewire\Documents\AiAssistant;
use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\Document;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('pdfs');
});

it('answers a chat question, persisting both turns and citing the source page', function () {
    fakeAi([
        '*/pdf/extract-text' => fakeExtractText([
            ['page_number' => 1, 'text' => 'Invoice total amount due immediately'],
            ['page_number' => 2, 'text' => 'Weather forecast rain expected tomorrow'],
        ]),
        '*/ai/chat' => Http::response(['answer' => 'The total amount is due immediately (p. 1).', 'model' => 'claude-opus-4-8']),
    ]);
    config()->set('services.ai.retrieval_top_k', 1);

    $user = User::factory()->create();
    $document = Document::factory()->for($user)->create(['path' => 'documents/d.pdf']);
    Storage::disk('pdfs')->put('documents/d.pdf', '%PDF');

    Livewire::actingAs($user)
        ->test(AiAssistant::class, ['document' => $document])
        ->set('question', 'what is the total amount')
        ->call('ask')
        ->assertHasNoErrors()
        ->assertSet('question', '');

    $conversation = $document->aiConversations()->first();
    expect($conversation)->not->toBeNull()
        ->and($conversation->user_id)->toBe($user->id);

    $messages = $conversation->messages()->get();
    expect($messages)->toHaveCount(2)
        ->and($messages[0]->role)->toBe(AiMessageRole::User)
        ->and($messages[0]->content)->toBe('what is the total amount')
        ->and($messages[1]->role)->toBe(AiMessageRole::Assistant)
        ->and($messages[1]->content)->toBe('The total amount is due immediately (p. 1).')
        ->and($messages[1]->meta['pages'])->toBe([1]);
});

it('ignores an empty question', function () {
    fakeAi();
    $user = User::factory()->create();
    $document = Document::factory()->for($user)->create();

    Livewire::actingAs($user)
        ->test(AiAssistant::class, ['document' => $document])
        ->set('question', '   ')
        ->call('ask')
        ->assertHasNoErrors();

    expect($document->aiConversations()->count())->toBe(0);
});

it('summarizes the document into the panel', function () {
    fakeAi(['*/ai/summarize' => Http::response(['summary' => 'This is the whole-document summary.', 'model' => 'claude-opus-4-8'])]);

    $user = User::factory()->create();
    $document = Document::factory()->for($user)->create(['path' => 'documents/d.pdf']);
    Storage::disk('pdfs')->put('documents/d.pdf', '%PDF');

    Livewire::actingAs($user)
        ->test(AiAssistant::class, ['document' => $document])
        ->set('tab', 'summarize')
        ->call('summarizeDocument')
        ->assertHasNoErrors()
        ->assertSet('summary', 'This is the whole-document summary.')
        ->assertSee('This is the whole-document summary.');
});

it('translates the document into the chosen language', function () {
    fakeAi(['*/ai/translate' => Http::response([
        'translated' => 'Hola mundo.',
        'target_language' => 'Spanish',
        'model' => 'claude-opus-4-8',
    ])]);

    $user = User::factory()->create();
    $document = Document::factory()->for($user)->create(['path' => 'documents/d.pdf']);
    Storage::disk('pdfs')->put('documents/d.pdf', '%PDF');

    Livewire::actingAs($user)
        ->test(AiAssistant::class, ['document' => $document])
        ->set('tab', 'translate')
        ->set('targetLanguage', 'Spanish')
        ->call('translateDocument')
        ->assertHasNoErrors()
        ->assertSet('translation', 'Hola mundo.');
});

it('rate limits AI actions per user', function () {
    config()->set('services.ai.rate_limit_per_minute', 1);
    fakeAi();

    $user = User::factory()->create();
    $document = Document::factory()->for($user)->create(['path' => 'documents/d.pdf']);
    Storage::disk('pdfs')->put('documents/d.pdf', '%PDF');

    $component = Livewire::actingAs($user)->test(AiAssistant::class, ['document' => $document]);

    $component->set('question', 'first question')->call('ask')->assertHasNoErrors();
    $component->set('question', 'second question')->call('ask')->assertHasErrors('question');

    // Only the first question produced a conversation turn.
    expect(AiMessage::count())->toBe(2);
});

it('clears the conversation', function () {
    $user = User::factory()->create();
    $document = Document::factory()->for($user)->create();
    $conversation = AiConversation::factory()->for($document)->create(['user_id' => $user->id]);
    AiMessage::factory()->for($conversation, 'conversation')->count(2)->create();

    Livewire::actingAs($user)
        ->test(AiAssistant::class, ['document' => $document])
        ->call('clearChat')
        ->assertHasNoErrors();

    expect(AiConversation::find($conversation->id))->toBeNull()
        ->and(AiMessage::count())->toBe(0);
});

it('sends the selected LLM provider with AI requests and remembers it', function () {
    fakeAi(['*/ai/summarize' => Http::response(['summary' => 'Resumen.', 'model' => 'gemini-2.0-flash'])]);

    $user = User::factory()->create();
    $document = Document::factory()->for($user)->create(['path' => 'documents/d.pdf']);
    Storage::disk('pdfs')->put('documents/d.pdf', '%PDF');

    Livewire::actingAs($user)
        ->test(AiAssistant::class, ['document' => $document])
        ->set('provider', 'gemini')
        ->set('tab', 'summarize')
        ->call('summarizeDocument')
        ->assertHasNoErrors()
        ->assertSet('provider', 'gemini');

    expect(session('ai.provider'))->toBe('gemini');

    Http::assertSent(fn ($request) => str_contains($request->url(), '/ai/summarize')
        && data_get(json_decode($request->body(), true), 'provider') === 'gemini');
});

it('falls back to the default provider when an unknown one is selected', function () {
    fakeAi();

    $user = User::factory()->create();
    $document = Document::factory()->for($user)->create();

    Livewire::actingAs($user)
        ->test(AiAssistant::class, ['document' => $document])
        ->set('provider', 'totally-made-up')
        ->assertSet('provider', config('services.ai.default_provider'));
});

it('forbids a non-owner from using the assistant', function () {
    $document = Document::factory()->for(User::factory())->create();

    Livewire::actingAs(User::factory()->create())
        ->test(AiAssistant::class, ['document' => $document])
        ->assertForbidden();
});
