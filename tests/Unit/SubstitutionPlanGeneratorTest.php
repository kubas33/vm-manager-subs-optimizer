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

    $pointThresholds = collect($plans)
        ->flatMap(fn (array $plan): array => $plan['slots'])
        ->flatMap(fn (array $slot): array => $slot['sets'])
        ->pluck('point_threshold')
        ->filter()
        ->unique()
        ->values()
        ->all();

    expect(count($plans))->toBeGreaterThan(256)
        ->and($plans[0]['slots'])->toHaveCount(2)
        ->and($plans[0]['slots'][0]['position'])->toBe(PlayerPosition::Setter->value)
        ->and($plans[0]['slots'][1]['position'])->toBe(PlayerPosition::Opposite->value)
        ->and($plans[0]['slots'][0]['sets'])->toHaveCount(3)
        ->and($plans[0]['slots'][1]['sets'])->toHaveCount(3)
        ->and($pointThresholds)->toContain(1, 5)
        ->and(max($pointThresholds))->toBeLessThanOrEqual(5);
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

    expect(count($plans))->toBeGreaterThan(162);

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

test('substitution plan generator excludes injured players from candidates', function () {
    $scenario = MatchScenario::fromInput('25:20, 25:18, 25:22', 'Standardowe 3:0');

    $injuredSetter = Player::factory()->make([
        'id' => 25,
        'name' => 'Injured Setter',
        'position' => PlayerPosition::Setter,
        'training_bar' => 0,
        'is_injured' => true,
    ]);
    $healthySetter = Player::factory()->make([
        'id' => 26,
        'name' => 'Healthy Setter',
        'position' => PlayerPosition::Setter,
        'training_bar' => 20,
    ]);
    $opposite = Player::factory()->make([
        'id' => 27,
        'name' => 'Healthy Opposite',
        'position' => PlayerPosition::Opposite,
    ]);

    $plans = (new SubstitutionPlanGenerator)->generate([
        ['slot_number' => 1, 'position' => PlayerPosition::Setter, 'players' => [$injuredSetter, $healthySetter]],
        ['slot_number' => 2, 'position' => PlayerPosition::Opposite, 'players' => [$opposite]],
    ], $scenario);

    expect($plans)->not->toBeEmpty();

    foreach ($plans as $plan) {
        expect(collect($plan['slots'])
            ->flatMap(fn (array $slot): array => [
                $slot['starter']['id'],
                ...collect($slot['sets'])->flatMap(fn (array $set): array => [
                    $set['starter_player']['id'],
                    $set['active_player']['id'],
                ])->all(),
            ])
            ->all())->not->toContain($injuredSetter->id);
    }
});

test('substitution plan generator rejects more than three analyzed slots', function () {
    $scenario = MatchScenario::fromInput('25:20, 25:18, 25:22', 'Standardowe 3:0');

    $setter = Player::factory()->make(['id' => 30, 'position' => PlayerPosition::Setter]);

    (new SubstitutionPlanGenerator)->generate([
        ['slot_number' => 1, 'position' => PlayerPosition::Setter, 'players' => [$setter]],
        ['slot_number' => 2, 'position' => PlayerPosition::Setter, 'players' => [$setter]],
        ['slot_number' => 3, 'position' => PlayerPosition::Setter, 'players' => [$setter]],
        ['slot_number' => 4, 'position' => PlayerPosition::Setter, 'players' => [$setter]],
    ], $scenario);
})->throws(InvalidArgumentException::class, 'Generator oczekuje od jednego do trzech analizowanych slotów.');

test('greedy generator supports three analyzed slots and point thresholds up to five', function () {
    $scenario = MatchScenario::fromInput('25:12, 25:14, 25:13', 'Łatwe 3:0');
    $middleA = Player::factory()->make(['id' => 40, 'position' => PlayerPosition::MiddleBlocker, 'training_bar' => 0]);
    $middleB = Player::factory()->make(['id' => 41, 'position' => PlayerPosition::MiddleBlocker, 'training_bar' => 5]);
    $middleC = Player::factory()->make(['id' => 42, 'position' => PlayerPosition::MiddleBlocker, 'training_bar' => 10]);
    $oppositeA = Player::factory()->make(['id' => 43, 'position' => PlayerPosition::Opposite, 'training_bar' => 0]);
    $oppositeB = Player::factory()->make(['id' => 44, 'position' => PlayerPosition::Opposite, 'training_bar' => 5]);

    $plans = (new SubstitutionPlanGenerator)->generateGreedy([
        ['slot_number' => 1, 'position' => PlayerPosition::MiddleBlocker, 'players' => [$middleA, $middleB, $middleC]],
        ['slot_number' => 2, 'position' => PlayerPosition::MiddleBlocker, 'players' => [$middleA, $middleB, $middleC]],
        ['slot_number' => 3, 'position' => PlayerPosition::Opposite, 'players' => [$oppositeA, $oppositeB]],
    ], $scenario);

    $thresholds = collect($plans)
        ->flatMap(fn (array $plan): array => $plan['slots'])
        ->flatMap(fn (array $slot): array => $slot['sets'])
        ->pluck('point_threshold')
        ->filter();

    expect($plans)->not->toBeEmpty()
        ->and($plans[0]['slots'])->toHaveCount(3)
        ->and($thresholds->every(fn (int $threshold): bool => $threshold >= 1 && $threshold <= 5))->toBeTrue();
});
