<?php

use App\Enums\PlayerPosition;
use App\MatchScenario;
use App\Models\Player;
use App\SubstitutionPlanGenerator;

test('substitution plan generator creates legal variants for two different positions', function () {
    $scenario = MatchScenario::fromInput('25:20, 25:18, 25:22', 'Standardowe 3:0');

    $setterA = Player::factory()->make(['id' => 1, 'name' => 'Setter A', 'position' => PlayerPosition::Setter]);
    $setterB = Player::factory()->make(['id' => 2, 'name' => 'Setter B', 'position' => PlayerPosition::Setter]);
    $oppositeA = Player::factory()->make(['id' => 3, 'name' => 'Opposite A', 'position' => PlayerPosition::Opposite]);
    $oppositeB = Player::factory()->make(['id' => 4, 'name' => 'Opposite B', 'position' => PlayerPosition::Opposite]);

    $plans = (new SubstitutionPlanGenerator)->generate([
        ['slot_number' => 1, 'position' => PlayerPosition::Setter, 'players' => [$setterA, $setterB]],
        ['slot_number' => 2, 'position' => PlayerPosition::Opposite, 'players' => [$oppositeA, $oppositeB]],
    ], $scenario);

    expect($plans)->toHaveCount(256)
        ->and($plans[0]['slots'])->toHaveCount(2)
        ->and($plans[0]['slots'][0]['position'])->toBe(PlayerPosition::Setter->value)
        ->and($plans[0]['slots'][1]['position'])->toBe(PlayerPosition::Opposite->value)
        ->and($plans[0]['slots'][0]['sets'])->toHaveCount(3)
        ->and($plans[0]['slots'][1]['sets'])->toHaveCount(3);
});

test('substitution plan generator supports two slots with the same position without duplicating active players', function () {
    $scenario = MatchScenario::fromInput('25:20, 25:18, 25:22', 'Standardowe 3:0');

    $middleA = Player::factory()->make(['id' => 10, 'name' => 'Middle A', 'position' => PlayerPosition::MiddleBlocker]);
    $middleB = Player::factory()->make(['id' => 11, 'name' => 'Middle B', 'position' => PlayerPosition::MiddleBlocker]);
    $middleC = Player::factory()->make(['id' => 12, 'name' => 'Middle C', 'position' => PlayerPosition::MiddleBlocker]);

    $plans = (new SubstitutionPlanGenerator)->generate([
        ['slot_number' => 1, 'position' => PlayerPosition::MiddleBlocker, 'players' => [$middleA, $middleB, $middleC]],
        ['slot_number' => 2, 'position' => PlayerPosition::MiddleBlocker, 'players' => [$middleA, $middleB, $middleC]],
    ], $scenario);

    expect($plans)->toHaveCount(162);

    foreach ($plans as $plan) {
        for ($setIndex = 0; $setIndex < 3; $setIndex++) {
            $activeIds = collect($plan['slots'])
                ->map(fn (array $slot): int => $slot['sets'][$setIndex]['active_player']['id'])
                ->all();

            expect(array_unique($activeIds))->toHaveCount(2);
        }
    }
});

test('substitution plan generator returns no plans when there are too few players for same-position slots', function () {
    $scenario = MatchScenario::fromInput('25:20, 25:18, 25:22', 'Standardowe 3:0');

    $middleA = Player::factory()->make(['id' => 20, 'name' => 'Middle A', 'position' => PlayerPosition::MiddleBlocker]);

    $plans = (new SubstitutionPlanGenerator)->generate([
        ['slot_number' => 1, 'position' => PlayerPosition::MiddleBlocker, 'players' => [$middleA]],
        ['slot_number' => 2, 'position' => PlayerPosition::MiddleBlocker, 'players' => [$middleA]],
    ], $scenario);

    expect($plans)->toBe([]);
});

test('substitution plan generator requires exactly two analyzed slots', function () {
    $scenario = MatchScenario::fromInput('25:20, 25:18, 25:22', 'Standardowe 3:0');

    (new SubstitutionPlanGenerator)->generate([
        ['slot_number' => 1, 'position' => PlayerPosition::Setter, 'players' => []],
    ], $scenario);
})->throws(InvalidArgumentException::class, 'Generator oczekuje dokładnie dwóch analizowanych slotów.');
