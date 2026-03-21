<?php

use App\Models\User;

test('guests can visit the dashboard when app auth is disabled', function () {
    $response = $this->get(route('dashboard'));

    $response->assertOk();
    $response->assertSee('VM Manager Subs Optimizer');
    $response->assertSee('Przejdź do zawodników');
});

test('guests are redirected to the login page when app auth is enabled', function () {
    config()->set('auth.disable_auth', false);

    $this->get(route('dashboard'))
        ->assertRedirect(route('login'));
});

test('authenticated users can visit the dashboard', function () {
    config()->set('auth.disable_auth', false);

    $user = User::factory()->create();
    $this->actingAs($user);

    $response = $this->get(route('dashboard'));
    $response->assertOk();
    $response->assertSee('VM Manager Subs Optimizer');
    $response->assertSee('Przejdź do zawodników');
});
