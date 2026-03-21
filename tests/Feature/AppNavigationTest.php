<?php

use App\Models\User;

test('authenticated users can visit players and optimizer pages', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    $this->get(route('players.index'))
        ->assertOk()
        ->assertSee('Zawodnicy');

    $this->get(route('optimizer.create'))
        ->assertOk()
        ->assertSee('Optymalizacja skladu');

    $this->get(route('optimizer.result'))
        ->assertOk()
        ->assertSee('Wynik optymalizacji');
});

test('guests are redirected to login from mvp application pages', function () {
    $this->get(route('players.index'))->assertRedirect(route('login'));
    $this->get(route('optimizer.create'))->assertRedirect(route('login'));
    $this->get(route('optimizer.result'))->assertRedirect(route('login'));
});
