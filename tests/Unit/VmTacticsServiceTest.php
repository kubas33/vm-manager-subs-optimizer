<?php

use App\Enums\PlayerPosition;
use App\Models\Player;
use App\VmTacticsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * @return array{slots: list<array{key: string, player: Player}>}
 */
function tacticsLineupRecommendation(array $overrides = []): array
{
    $players = [
        'setter' => Player::factory()->forPosition(PlayerPosition::Setter)->withVmPlayerId(101)->create(['name' => 'Starter Setter', 'training_bar' => 12]),
        'outside_1' => Player::factory()->forPosition(PlayerPosition::OutsideHitter)->withVmPlayerId(102)->create(['name' => 'Starter Outside 1', 'training_bar' => 10]),
        'middle_1' => Player::factory()->forPosition(PlayerPosition::MiddleBlocker)->withVmPlayerId(103)->create(['name' => 'Starter Middle 1', 'training_bar' => 8]),
        'opposite' => Player::factory()->forPosition(PlayerPosition::Opposite)->withVmPlayerId(104)->create(['name' => 'Starter Opposite', 'training_bar' => 5]),
        'outside_2' => Player::factory()->forPosition(PlayerPosition::OutsideHitter)->withVmPlayerId(105)->create(['name' => 'Starter Outside 2', 'training_bar' => 18]),
        'middle_2' => Player::factory()->forPosition(PlayerPosition::MiddleBlocker)->withVmPlayerId(106)->create(['name' => 'Starter Middle 2', 'training_bar' => 22]),
        'libero' => Player::factory()->forPosition(PlayerPosition::Libero)->withVmPlayerId(107)->create(['name' => 'Starter Libero', 'training_bar' => 7]),
    ];

    foreach ($overrides as $slotKey => $player) {
        $players[$slotKey] = $player;
    }

    return [
        'kind' => 'primary',
        'slots' => collect($players)
            ->map(fn (Player $player, string $key): array => [
                'key' => $key,
                'player' => $player,
            ])
            ->values()
            ->all(),
    ];
}

test('tactics payload maps court slots to player1 through player7', function () {
    $recommendation = tacticsLineupRecommendation();

    $payload = (new VmTacticsService)->buildPayload($recommendation, collect(), [
        'block1' => 4,
        'blockPassive1' => 2,
        'block2' => 5,
        'blockPassive2' => 3,
        'block3' => 6,
        'blockPassive3' => 1,
    ], 'League', 0);

    expect($payload)->toMatchArray([
        'matchType' => 'League',
        'matchId' => 0,
        'player1' => 101,
        'player2' => 102,
        'player3' => 103,
        'player4' => 104,
        'player5' => 105,
        'player6' => 106,
        'player7' => 107,
        'player8' => null,
        'player9' => null,
        'player10' => null,
        'player11' => null,
        'player12' => null,
        'block1' => 4,
        'blockPassive1' => 2,
        'block2' => 5,
        'blockPassive2' => 3,
        'block3' => 6,
        'blockPassive3' => 1,
    ]);
});

test('tactics payload fills the bench with the five lowest remaining training bars', function () {
    $recommendation = tacticsLineupRecommendation();

    $bench = collect([
        Player::factory()->forPosition(PlayerPosition::Setter)->withVmPlayerId(201)->create(['name' => 'Bench Setter', 'training_bar' => 40]),
        Player::factory()->forPosition(PlayerPosition::Opposite)->withVmPlayerId(202)->create(['name' => 'Bench Opposite', 'training_bar' => 3]),
        Player::factory()->forPosition(PlayerPosition::MiddleBlocker)->withVmPlayerId(203)->create(['name' => 'Bench Middle', 'training_bar' => 9]),
        Player::factory()->forPosition(PlayerPosition::OutsideHitter)->withVmPlayerId(204)->create(['name' => 'Bench Outside A', 'training_bar' => 15]),
        Player::factory()->forPosition(PlayerPosition::Libero)->withVmPlayerId(205)->create(['name' => 'Bench Libero', 'training_bar' => 11]),
        Player::factory()->forPosition(PlayerPosition::OutsideHitter)->withVmPlayerId(206)->create(['name' => 'Bench Outside B', 'training_bar' => 50]),
    ]);

    $payload = (new VmTacticsService)->buildPayload(
        $recommendation,
        $bench->concat(collect($recommendation['slots'])->pluck('player')),
    );

    expect($payload['player8'])->toBe(202)
        ->and($payload['player9'])->toBe(203)
        ->and($payload['player10'])->toBe(205)
        ->and($payload['player11'])->toBe(204)
        ->and($payload['player12'])->toBe(201);
});

test('tactics payload rejects a starter without a VM player ID', function () {
    $playerWithoutVmId = Player::factory()->forPosition(PlayerPosition::Setter)->create([
        'name' => 'No VM ID',
        'training_bar' => 1,
        'vm_player_id' => null,
    ]);

    expect(fn () => (new VmTacticsService)->buildPayload(
        tacticsLineupRecommendation(['setter' => $playerWithoutVmId]),
    ))->toThrow(InvalidArgumentException::class);
});

test('push recommendation posts the lineup and preserves existing block settings', function () {
    config()->set('services.vm_tactics.url', 'https://faster.vm-manager.org/api/tactics');
    config()->set('services.vm_tactics.api_token', 'tactics-token');
    config()->set('services.vm_training_import.api_token', null);

    Http::fake([
        'https://faster.vm-manager.org/api/tactics*' => Http::sequence()
            ->push([
                'idPlayer1' => 999,
                'block1' => 4,
                'blockPassive1' => 2,
                'block2' => 5,
                'blockPassive2' => 3,
                'block3' => 6,
                'blockPassive3' => 1,
            ])
            ->push(['ok' => true]),
    ]);

    $recommendation = tacticsLineupRecommendation();

    (new VmTacticsService)->pushRecommendation($recommendation, collect($recommendation['slots'])->pluck('player'));

    Http::assertSentInOrder([
        function (Request $request): bool {
            return $request->method() === 'GET'
                && str_starts_with($request->url(), 'https://faster.vm-manager.org/api/tactics')
                && $request['type'] === 'League'
                && (string) $request['matchId'] === '0'
                && $request->header('Authorization') === ['Bearer tactics-token'];
        },
        function (Request $request): bool {
            return $request->method() === 'POST'
                && $request->url() === 'https://faster.vm-manager.org/api/tactics'
                && $request['matchType'] === 'League'
                && $request['player1'] === 101
                && $request['player4'] === 104
                && $request['player7'] === 107
                && $request['block1'] === 4
                && $request['blockPassive3'] === 1
                && $request->header('Authorization') === ['Bearer tactics-token'];
        },
    ]);
});
