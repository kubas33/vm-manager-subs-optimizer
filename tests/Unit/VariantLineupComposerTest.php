<?php

use App\Enums\PlayerPosition;
use App\Models\Player;
use App\VariantLineupComposer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * @return array{slots: list<array<string, mixed>>}
 */
function baseVariantLineup(): array
{
    $definitions = [
        ['setter', PlayerPosition::Setter, 101],
        ['outside_1', PlayerPosition::OutsideHitter, 102],
        ['middle_1', PlayerPosition::MiddleBlocker, 103],
        ['opposite', PlayerPosition::Opposite, 104],
        ['outside_2', PlayerPosition::OutsideHitter, 105],
        ['middle_2', PlayerPosition::MiddleBlocker, 106],
        ['libero', PlayerPosition::Libero, 107],
    ];

    return [
        'slots' => collect($definitions)->map(function (array $definition): array {
            [$key, $position, $vmPlayerId] = $definition;
            $player = Player::factory()->forPosition($position)->withVmPlayerId($vmPlayerId)->create();

            return [
                'key' => $key,
                'label' => $position->label(),
                'abbreviation' => $position->label(),
                'grid_row' => 1,
                'grid_column' => 1,
                'player' => $player,
                'training_bar' => $player->training_bar,
            ];
        })->all(),
    ];
}

/**
 * @param  list<array<string, mixed>>  $slots
 * @return array{slots: list<array<string, mixed>>}
 */
function variantPlan(array $slots): array
{
    return ['slots' => $slots];
}

/** @return array{id: int, name: string} */
function variantPlayerPayload(Player $player): array
{
    return ['id' => $player->id, 'name' => $player->name];
}

test('it overlays duplicated optimized positions onto their semantic court slots and keeps base starters', function () {
    $base = baseVariantLineup();
    $firstMiddle = Player::factory()->forPosition(PlayerPosition::MiddleBlocker)->withVmPlayerId(201)->create(['name' => 'Pierwszy środkowy']);
    $secondMiddle = Player::factory()->forPosition(PlayerPosition::MiddleBlocker)->withVmPlayerId(202)->create(['name' => 'Drugi środkowy']);
    $benchPlayer = Player::factory()->forPosition(PlayerPosition::MiddleBlocker)->withVmPlayerId(203)->create(['name' => 'Rezerwowy środkowy']);

    $variant = (new VariantLineupComposer)->compose($base, variantPlan([
        [
            'slot_number' => 2,
            'position' => PlayerPosition::MiddleBlocker->value,
            'starter' => variantPlayerPayload($secondMiddle),
            'sets' => [],
        ],
        [
            'slot_number' => 1,
            'position' => PlayerPosition::MiddleBlocker->value,
            'starter' => variantPlayerPayload($firstMiddle),
            'sets' => [[
                'substitution_player' => variantPlayerPayload($benchPlayer),
            ]],
        ],
    ]));

    expect($variant['is_sendable'])->toBeTrue()
        ->and($variant['lineup']['middle_1']['player']->id)->toBe($firstMiddle->id)
        ->and($variant['lineup']['middle_2']['player']->id)->toBe($secondMiddle->id)
        ->and($variant['lineup']['middle_1']['source'])->toBe('optimized')
        ->and($variant['lineup']['setter']['source'])->toBe('base')
        ->and($variant['bench'])->toHaveCount(5)
        ->and($variant['bench'][0]->id)->toBe($benchPlayer->id)
        ->and($variant['bench'][1])->toBeNull()
        ->and($variant['starter_vm_player_ids'])->toBe([101, 102, 201, 104, 105, 202, 107])
        ->and($variant['bench_vm_player_ids'])->toBe([203]);
});

test('it blocks a bench player who is also a starter and never puts them on the bench', function () {
    $base = baseVariantLineup();
    $setter = collect($base['slots'])->firstWhere('key', 'setter')['player'];

    $variant = (new VariantLineupComposer)->compose($base, variantPlan([[
        'slot_number' => 1,
        'position' => PlayerPosition::Setter->value,
        'starter' => variantPlayerPayload($setter),
        'sets' => [[
            'substitution_player' => variantPlayerPayload($setter),
        ]],
    ]]));

    expect($variant['is_sendable'])->toBeFalse()
        ->and(collect($variant['send_blockers'])->pluck('code'))->toContain('starter_on_bench')
        ->and(collect($variant['bench'])->filter())->toBeEmpty();
});

test('it replaces an untouched base starter who is required on the bench', function () {
    $base = baseVariantLineup();
    $outsideOne = collect($base['slots'])->firstWhere('key', 'outside_1')['player'];
    $outsideTwo = collect($base['slots'])->firstWhere('key', 'outside_2')['player'];
    $fallbackOutside = Player::factory()
        ->forPosition(PlayerPosition::OutsideHitter)
        ->withVmPlayerId(208)
        ->create(['name' => 'Zastępczy przyjmujący', 'training_bar' => 1]);

    $variant = (new VariantLineupComposer)->compose($base, variantPlan([[
        'slot_number' => 1,
        'position' => PlayerPosition::OutsideHitter->value,
        'starter' => variantPlayerPayload($outsideOne),
        'sets' => [[
            'substitution_player' => variantPlayerPayload($outsideTwo),
        ]],
    ]]));

    expect($variant['is_sendable'])->toBeTrue()
        ->and($variant['lineup']['outside_2']['source'])->toBe('base')
        ->and($variant['lineup']['outside_2']['player']->id)->toBe($fallbackOutside->id)
        ->and($variant['bench'][0]->id)->toBe($outsideTwo->id)
        ->and(collect($variant['send_blockers'])->pluck('code'))->not->toContain('starter_on_bench');
});

test('it replaces an untouched base starter duplicated by an optimized starter', function () {
    $base = baseVariantLineup();
    $outsideTwo = collect($base['slots'])->firstWhere('key', 'outside_2')['player'];
    $fallbackOutside = Player::factory()
        ->forPosition(PlayerPosition::OutsideHitter)
        ->withVmPlayerId(209)
        ->create(['name' => 'Zastępczy przyjmujący', 'training_bar' => 1]);

    $variant = (new VariantLineupComposer)->compose($base, variantPlan([[
        'slot_number' => 1,
        'position' => PlayerPosition::OutsideHitter->value,
        'starter' => variantPlayerPayload($outsideTwo),
        'sets' => [],
    ]]));

    expect($variant['is_sendable'])->toBeTrue()
        ->and($variant['lineup']['outside_1']['player']->id)->toBe($outsideTwo->id)
        ->and($variant['lineup']['outside_2']['player']->id)->toBe($fallbackOutside->id)
        ->and(collect($variant['send_blockers'])->pluck('code'))->not->toContain('duplicate_starter');
});

test('it reports bench overflow without treating a six-player bench as sendable', function () {
    $base = baseVariantLineup();
    $setter = Player::factory()->forPosition(PlayerPosition::Setter)->withVmPlayerId(201)->create();
    $outside = Player::factory()->forPosition(PlayerPosition::OutsideHitter)->withVmPlayerId(202)->create();
    $benchPlayers = Player::factory()->count(6)->sequence(
        fn ($sequence) => ['position' => $sequence->index % 2 === 0 ? PlayerPosition::Setter : PlayerPosition::OutsideHitter, 'vm_player_id' => 300 + $sequence->index]
    )->create();

    $variant = (new VariantLineupComposer)->compose($base, variantPlan([
        [
            'slot_number' => 1,
            'position' => PlayerPosition::Setter->value,
            'starter' => variantPlayerPayload($setter),
            'sets' => $benchPlayers->take(3)->map(fn (Player $player): array => ['substitution_player' => variantPlayerPayload($player)])->all(),
        ],
        [
            'slot_number' => 2,
            'position' => PlayerPosition::OutsideHitter->value,
            'starter' => variantPlayerPayload($outside),
            'sets' => $benchPlayers->skip(3)->map(fn (Player $player): array => ['substitution_player' => variantPlayerPayload($player)])->all(),
        ],
    ]));

    expect($variant['is_sendable'])->toBeFalse()
        ->and(collect($variant['send_blockers'])->pluck('code'))->toContain('bench_overflow')
        ->and($variant['bench'])->toHaveCount(5);
});

test('it identifies the player whose missing VM ID blocks sending', function () {
    $base = baseVariantLineup();
    $setter = Player::factory()->forPosition(PlayerPosition::Setter)->create(['name' => 'Bez VM']);

    $variant = (new VariantLineupComposer)->compose($base, variantPlan([[
        'slot_number' => 1,
        'position' => PlayerPosition::Setter->value,
        'starter' => variantPlayerPayload($setter),
        'sets' => [],
    ]]));

    expect($variant['is_sendable'])->toBeFalse()
        ->and(collect($variant['send_blockers'])->firstWhere('code', 'missing_vm_id'))->toMatchArray([
            'player_id' => $setter->id,
            'player_name' => 'Bez VM',
            'slot_key' => 'setter',
        ]);
});
