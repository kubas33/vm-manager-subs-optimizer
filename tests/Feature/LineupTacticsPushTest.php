<?php

use App\Enums\PlayerPosition;
use App\Models\Player;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

function seedCompleteTacticsRoster(): void
{
    Player::factory()->forPosition(PlayerPosition::Opposite)->withVmPlayerId(104)->create([
        'name' => 'Lineup Opposite Low',
        'training_bar' => 5,
    ]);
    Player::factory()->forPosition(PlayerPosition::Opposite)->withVmPlayerId(204)->create([
        'name' => 'Lineup Opposite Alt',
        'training_bar' => 35,
    ]);
    Player::factory()->forPosition(PlayerPosition::MiddleBlocker)->withVmPlayerId(103)->create([
        'name' => 'Lineup Middle Low',
        'training_bar' => 8,
    ]);
    Player::factory()->forPosition(PlayerPosition::MiddleBlocker)->withVmPlayerId(106)->create([
        'name' => 'Lineup Middle High',
        'training_bar' => 22,
    ]);
    Player::factory()->forPosition(PlayerPosition::MiddleBlocker)->withVmPlayerId(203)->create([
        'name' => 'Lineup Middle Alt',
        'training_bar' => 40,
    ]);
    Player::factory()->forPosition(PlayerPosition::OutsideHitter)->withVmPlayerId(102)->create([
        'name' => 'Lineup Outside Low',
        'training_bar' => 10,
    ]);
    Player::factory()->forPosition(PlayerPosition::OutsideHitter)->withVmPlayerId(105)->create([
        'name' => 'Lineup Outside Mid',
        'training_bar' => 18,
    ]);
    Player::factory()->forPosition(PlayerPosition::OutsideHitter)->withVmPlayerId(202)->create([
        'name' => 'Lineup Outside Alt',
        'training_bar' => 30,
    ]);
    Player::factory()->forPosition(PlayerPosition::Setter)->withVmPlayerId(101)->create([
        'name' => 'Lineup Setter Low',
        'training_bar' => 12,
    ]);
    Player::factory()->forPosition(PlayerPosition::Setter)->withVmPlayerId(201)->create([
        'name' => 'Lineup Setter Alt',
        'training_bar' => 45,
    ]);
    Player::factory()->forPosition(PlayerPosition::Libero)->withVmPlayerId(107)->create([
        'name' => 'Lineup Libero Low',
        'training_bar' => 7,
    ]);
    Player::factory()->forPosition(PlayerPosition::Libero)->withVmPlayerId(205)->create([
        'name' => 'Lineup Libero Alt',
        'training_bar' => 50,
    ]);
}

test('optimizer result page can send the primary lineup to VM Manager', function () {
    $this->actingAs(User::factory()->create());

    seedCompleteTacticsRoster();

    config()->set('vm-manager.api_url', 'https://faster.vm-manager.org');
    config()->set('vm-manager.api_token', 'tactics-token');

    Http::fake([
        'https://faster.vm-manager.org/api/tactics*' => Http::sequence()
            ->push([
                'block1' => 7,
                'blockPassive1' => 1,
                'block2' => 7,
                'blockPassive2' => 1,
                'block3' => 7,
                'blockPassive3' => 0,
            ])
            ->push(['ok' => true]),
    ]);

    Livewire::test('pages::optimizer.result')
        ->assertSee('Wyślij do gry')
        ->call('requestPushLineup', 0)
        ->assertSet('confirmingTacticsPush', true)
        ->call('confirmPushLineup')
        ->assertHasNoErrors()
        ->assertSet('confirmingTacticsPush', false)
        ->assertSee('Skład został wysłany do gry');

    Http::assertSent(function (Request $request): bool {
        return $request->method() === 'POST'
            && $request->url() === 'https://faster.vm-manager.org/api/tactics'
            && $request['matchType'] === 'League'
            && $request['player1'] === 101
            && $request['player2'] === 102
            && $request['player3'] === 103
            && $request['player4'] === 104
            && $request['player5'] === 105
            && $request['player6'] === 106
            && $request['player7'] === 107;
    });
});

test('optimizer result page does not offer send when a starter is missing a VM player ID', function () {
    $this->actingAs(User::factory()->create());

    seedCompleteTacticsRoster();

    Player::query()->update(['vm_player_id' => null]);

    Livewire::test('pages::optimizer.result')
        ->assertDontSee('Wyślij do gry')
        ->assertSee('Brak ID VM');
});
