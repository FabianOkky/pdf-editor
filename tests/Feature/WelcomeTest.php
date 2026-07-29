<?php

use App\Models\User;

it('renders the landing page for guests with sign-up CTAs', function () {
    $this->get('/')
        ->assertOk()
        ->assertSee('Edit your PDFs live')
        ->assertSee('Get started')
        ->assertSee(route('register'), escape: false)
        ->assertSee(route('login'), escape: false);
});

it('redirects authenticated users straight to the dashboard', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/')
        ->assertRedirect(route('dashboard'));
});
