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

test('tactics payload can include reserves selected by training bar', function () {
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
        collect(),
        [],
        'League',
        0,
        $bench->sortBy('training_bar')->take(5)->pluck('vm_player_id')->all(),
    );

    expect($payload['player8'])->toBe(202)
        ->and($payload['player9'])->toBe(203)
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

test('push recommendation posts the lineup and does not read existing tactics', function () {
    config()->set('vm-manager.api_url', 'https://faster.vm-manager.org');
    config()->set('vm-manager.api_token', 'tactics-token');

    Http::fake([
        'https://faster.vm-manager.org/api/tactics*' => Http::response(['ok' => true]),
    ]);

    $recommendation = tacticsLineupRecommendation();

    (new VmTacticsService)->pushRecommendation($recommendation, collect($recommendation['slots'])->pluck('player'));

    Http::assertSentCount(1);
    Http::assertSent(function (Request $request): bool {
        return $request->method() === 'POST'
            && $request->url() === 'https://faster.vm-manager.org/api/tactics'
            && $request['matchType'] === 'League'
            && $request['player1'] === 101
            && $request['player4'] === 104
            && $request['player7'] === 107
            && $request['block1'] === 7
            && $request['blockPassive3'] === 0
            && $request->header('Authorization') === ['Bearer tactics-token'];
    });
});

test('push recommendation sends local reserves without reading existing tactics', function () {
    config()->set('vm-manager.api_url', 'https://faster.vm-manager.org');
    config()->set('vm-manager.api_token', 'tactics-token');

    Http::fake(['https://faster.vm-manager.org/api/tactics*' => Http::response(['ok' => true])]);

    $reserve = Player::factory()->forPosition(PlayerPosition::Setter)->withVmPlayerId(201)->create([
        'name' => 'Reserve Setter',
        'training_bar' => 30,
    ]);
    $payload = (new VmTacticsService)->pushRecommendation(
        tacticsLineupRecommendation(),
        collect([$reserve]),
    );

    expect($payload['player8'])->toBe(201)
        ->and($payload['player9'])->toBeNull();
    Http::assertSentCount(1);
});

test('variant tactics posts the provided starting lineup and reserves', function () {
    config()->set('vm-manager.api_url', 'https://faster.vm-manager.org');
    config()->set('vm-manager.api_token', 'tactics-token');

    Http::fake([
        'https://faster.vm-manager.org/api/tactics*' => Http::response(['ok' => true]),
    ]);

    $payload = (new VmTacticsService)->pushVariantTactics([
        101, 102, 103, 104, 105, 106, 107,
    ], [206, 202, 206, 203, 204, 205]);

    expect($payload)->toMatchArray([
        'player1' => 101,
        'player2' => 102,
        'player3' => 103,
        'player4' => 104,
        'player5' => 105,
        'player6' => 106,
        'player7' => 107,
        'player8' => 206,
        'player9' => 202,
        'player10' => 203,
        'player11' => 204,
        'player12' => 205,
        'block1' => 7,
        'blockPassive3' => 0,
    ]);

    Http::assertSent(function (Request $request): bool {
        return $request->method() === 'POST'
            && $request->url() === 'https://faster.vm-manager.org/api/tactics'
            && $request['player1'] === 101
            && $request['player7'] === 107
            && $request['player8'] === 206
            && $request['player9'] === 202
            && $request['player10'] === 203
            && $request['player11'] === 204
            && $request['player12'] === 205
            && $request['block1'] === 7
            && $request['blockPassive3'] === 0;
    });
    Http::assertSentCount(1);
});
