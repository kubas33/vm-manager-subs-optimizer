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

test('players page renders position filter option only once', function () {
    $this->actingAs(User::factory()->create());

    $response = $this->get(route('players.index'));

    $response->assertOk();

    expect(substr_count($response->getContent(), 'Wszystkie pozycje'))->toBe(1);
});

test('player can be created from players page', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test('pages::players.index')
        ->set('vmPlayerId', 2060721)
        ->set('name', 'Karol Nowicki')
        ->set('position', PlayerPosition::MiddleBlocker->value)
        ->set('trainingBar', 44)
        ->set('active', true)
        ->call('savePlayer')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('players', [
        'vm_player_id' => 2060721,
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
        'vm_player_id' => 1976867,
        'name' => 'Marek Przed Zmiana',
        'position' => PlayerPosition::Setter,
        'training_bar' => 12,
        'active' => true,
    ]);

    Livewire::test('pages::players.index')
        ->call('editPlayer', $player->id)
        ->set('vmPlayerId', 2060721)
        ->set('name', 'Marek Po Zmianie')
        ->set('position', PlayerPosition::Opposite->value)
        ->set('trainingBar', 57)
        ->set('active', false)
        ->call('savePlayer')
        ->assertHasNoErrors();

    expect($player->fresh())
        ->vm_player_id->toBe(2060721)
        ->name->toBe('Marek Po Zmianie')
        ->position->toBe(PlayerPosition::Opposite)
        ->training_bar->toBe(57)
        ->active->toBeFalse();
});

test('player vm id must be unique when provided', function () {
    $this->actingAs(User::factory()->create());

    Player::factory()->create([
        'vm_player_id' => 2060721,
    ]);

    Livewire::test('pages::players.index')
        ->set('vmPlayerId', 2060721)
        ->set('name', 'Unikalny Testowy')
        ->set('position', PlayerPosition::MiddleBlocker->value)
        ->set('trainingBar', 44)
        ->call('savePlayer')
        ->assertHasErrors([
            'vmPlayerId',
        ]);
});

test('player can be deleted from players page', function () {
    $this->actingAs(User::factory()->create());

    $player = Player::factory()->create([
        'name' => 'Do Skasowania',
        'position' => PlayerPosition::Libero,
    ]);

    Livewire::test('pages::players.index')
        ->call('requestDeletePlayer', $player->id)
        ->assertSet('confirmingPlayerDeletion', true)
        ->assertSet('playerIdPendingDeletion', $player->id)
        ->call('deletePlayer')
        ->assertHasNoErrors();

    $this->assertDatabaseMissing('players', [
        'id' => $player->id,
    ]);
});

test('deleting player clears edit form when same player was being edited', function () {
    $this->actingAs(User::factory()->create());

    $player = Player::factory()->create([
        'name' => 'Edytowany Potem Skasowany',
    ]);

    Livewire::test('pages::players.index')
        ->call('editPlayer', $player->id)
        ->call('requestDeletePlayer', $player->id)
        ->call('deletePlayer')
        ->assertSet('editingPlayerId', null);

    $this->assertDatabaseMissing('players', [
        'id' => $player->id,
    ]);
});

test('players list can be filtered by position and status', function () {
    $this->actingAs(User::factory()->create());

    Player::factory()->forPosition(PlayerPosition::Setter)->create([
        'name' => 'Rozgrywający Aktywny',
        'active' => true,
    ]);

    Player::factory()->forPosition(PlayerPosition::MiddleBlocker)->inactive()->create([
        'name' => 'Środkowy Nieaktywny',
    ]);

    Livewire::test('pages::players.index')
        ->set('filterPosition', PlayerPosition::Setter->value)
        ->set('filterActive', 'active')
        ->assertSee('Rozgrywający Aktywny')
        ->assertDontSee('Środkowy Nieaktywny');
});
