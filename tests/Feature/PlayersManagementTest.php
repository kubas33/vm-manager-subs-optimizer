<?php

use App\Enums\PlayerPosition;
use App\Models\Player;
use App\Models\User;
use Livewire\Livewire;

test('players page shows seeded players for authenticated users', function () {
    $this->actingAs(User::factory()->create());

    Player::factory()->forPosition(PlayerPosition::Setter)->create([
        'name' => 'Jan Testowy',
    ]);

    $this->get(route('players.index'))
        ->assertOk()
        ->assertSee('Jan Testowy');
});

test('player can be created from players page', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test('pages::players.index')
        ->set('name', 'Karol Nowicki')
        ->set('position', PlayerPosition::MiddleBlocker->value)
        ->set('trainingBar', 44)
        ->set('active', true)
        ->call('savePlayer')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('players', [
        'name' => 'Karol Nowicki',
        'position' => PlayerPosition::MiddleBlocker->value,
        'training_bar' => 44,
        'active' => true,
    ]);
});

test('player form validates required fields and training bar range', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test('pages::players.index')
        ->set('name', '')
        ->set('position', '')
        ->set('trainingBar', 140)
        ->call('savePlayer')
        ->assertHasErrors([
            'name',
            'position',
            'trainingBar',
        ]);
});

test('existing player can be updated from players page', function () {
    $this->actingAs(User::factory()->create());

    $player = Player::factory()->create([
        'name' => 'Marek Przed Zmiana',
        'position' => PlayerPosition::Setter,
        'training_bar' => 12,
        'active' => true,
    ]);

    Livewire::test('pages::players.index')
        ->call('editPlayer', $player->id)
        ->set('name', 'Marek Po Zmianie')
        ->set('position', PlayerPosition::Opposite->value)
        ->set('trainingBar', 57)
        ->set('active', false)
        ->call('savePlayer')
        ->assertHasNoErrors();

    expect($player->fresh())
        ->name->toBe('Marek Po Zmianie')
        ->position->toBe(PlayerPosition::Opposite)
        ->training_bar->toBe(57)
        ->active->toBeFalse();
});

test('players list can be filtered by position and status', function () {
    $this->actingAs(User::factory()->create());

    Player::factory()->forPosition(PlayerPosition::Setter)->create([
        'name' => 'Rozgrywajacy Aktywny',
        'active' => true,
    ]);

    Player::factory()->forPosition(PlayerPosition::MiddleBlocker)->inactive()->create([
        'name' => 'Srodkowy Nieaktywny',
    ]);

    Livewire::test('pages::players.index')
        ->set('filterPosition', PlayerPosition::Setter->value)
        ->set('filterActive', 'active')
        ->assertSee('Rozgrywajacy Aktywny')
        ->assertDontSee('Srodkowy Nieaktywny');
});
