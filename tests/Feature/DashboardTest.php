<?php

use App\Models\Document;
use App\Models\User;

test('guests are redirected to the login page', function () {
    $response = $this->get(route('dashboard'));
    $response->assertRedirect(route('login'));
});

test('authenticated users can visit the dashboard', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $response = $this->get(route('dashboard'));
    $response->assertOk();
});

test('the dashboard shows recent documents and headline stats', function () {
    $user = User::factory()->create();
    Document::factory()->for($user)->create(['title' => 'My Recent Report', 'page_count' => 5]);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Recent documents')
        ->assertSee('My Recent Report');
});

test('the dashboard shows an empty state with no documents', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('No documents yet');
});

test('the dashboard only counts the current user’s documents', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();

    Document::factory()->for($user)->create(['title' => 'Mine']);
    Document::factory()->for($other)->create(['title' => 'Theirs']);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Mine')
        ->assertDontSee('Theirs');
});
