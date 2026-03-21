<?php

use App\Models\User;

test('guests can visit players and optimizer pages when app auth is disabled', function () {
    $this->get(route('players.index'))
        ->assertOk()
        ->assertSee('Zawodnicy');

    $this->get(route('optimizer.create'))
        ->assertOk()
        ->assertSee('Optymalizacja składu');

    $this->get(route('optimizer.result'))
        ->assertOk()
        ->assertSee('Wynik optymalizacji');
});

test('guests are redirected to login from mvp application pages when app auth is enabled', function () {
    config()->set('auth.disable_auth', false);

    $this->get(route('players.index'))->assertRedirect(route('login'));
    $this->get(route('optimizer.create'))->assertRedirect(route('login'));
    $this->get(route('optimizer.result'))->assertRedirect(route('login'));
});

test('authenticated users can visit players and optimizer pages', function () {
    config()->set('auth.disable_auth', false);

    $user = User::factory()->create();

    $this->actingAs($user);

    $this->get(route('players.index'))
        ->assertOk()
        ->assertSee('Zawodnicy');

    $this->get(route('optimizer.create'))
        ->assertOk()
        ->assertSee('Optymalizacja składu');

    $this->get(route('optimizer.result'))
        ->assertOk()
        ->assertSee('Wynik optymalizacji');
});
