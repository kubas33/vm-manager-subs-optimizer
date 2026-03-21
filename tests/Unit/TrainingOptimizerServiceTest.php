<?php

use App\Enums\PlayerPosition;
use App\MatchScenario;
use App\Models\Player;
use App\SubstitutionPlanGenerator;
use App\TrainingGainCalculator;
use App\TrainingOptimizerService;

test('training optimizer service ranks plans by final training bar sum', function () {
    $scenario = MatchScenario::fromInput('25:0, 25:0, 25:0', 'Łatwe 3:0');

    $setterA = Player::factory()->make(['id' => 1, 'name' => 'Setter A', 'position' => PlayerPosition::Setter, 'training_bar' => 0]);
    $setterB = Player::factory()->make(['id' => 2, 'name' => 'Setter B', 'position' => PlayerPosition::Setter, 'training_bar' => 0]);
    $oppositeA = Player::factory()->make(['id' => 3, 'name' => 'Opposite A', 'position' => PlayerPosition::Opposite, 'training_bar' => 0]);
    $oppositeB = Player::factory()->make(['id' => 4, 'name' => 'Opposite B', 'position' => PlayerPosition::Opposite, 'training_bar' => 0]);

    $rankedPlans = new TrainingOptimizerService(
        new TrainingGainCalculator,
        new SubstitutionPlanGenerator,
    )->optimize([
        ['slot_number' => 1, 'position' => PlayerPosition::Setter, 'players' => [$setterA, $setterB]],
        ['slot_number' => 2, 'position' => PlayerPosition::Opposite, 'players' => [$oppositeA, $oppositeB]],
    ], $scenario, 5);

    expect($rankedPlans)->toHaveCount(5)
        ->and($rankedPlans[0]['final_training_bar_sum'])->toBe(150)
        ->and($rankedPlans[0]['wasted_actions'])->toBe(0)
        ->and($rankedPlans[0]['substitutions_count'])->toBe(4)
        ->and(collect($rankedPlans[0]['player_results'])->pluck('gained_training')->sort()->values()->all())->toBe([27, 27, 48, 48]);
});

test('training optimizer service prefers simpler plans when score and waste are tied', function () {
    $scenario = MatchScenario::fromInput('1:0, 1:0, 1:0', 'Krótki 3:0');

    $setterA = Player::factory()->make(['id' => 10, 'name' => 'Setter A', 'position' => PlayerPosition::Setter, 'training_bar' => 0]);
    $setterB = Player::factory()->make(['id' => 11, 'name' => 'Setter B', 'position' => PlayerPosition::Setter, 'training_bar' => 0]);
    $oppositeA = Player::factory()->make(['id' => 12, 'name' => 'Opposite A', 'position' => PlayerPosition::Opposite, 'training_bar' => 0]);
    $oppositeB = Player::factory()->make(['id' => 13, 'name' => 'Opposite B', 'position' => PlayerPosition::Opposite, 'training_bar' => 0]);

    $rankedPlans = new TrainingOptimizerService(
        new TrainingGainCalculator,
        new SubstitutionPlanGenerator,
    )->optimize([
        ['slot_number' => 1, 'position' => PlayerPosition::Setter, 'players' => [$setterA, $setterB]],
        ['slot_number' => 2, 'position' => PlayerPosition::Opposite, 'players' => [$oppositeA, $oppositeB]],
    ], $scenario, 20);

    expect($rankedPlans[0]['final_training_bar_sum'])->toBe(6)
        ->and($rankedPlans[0]['wasted_actions'])->toBe(0)
        ->and($rankedPlans[0]['substitutions_count'])->toBe(0);
});

test('training optimizer service respects ranking limit', function () {
    $scenario = MatchScenario::fromInput('25:20, 25:18, 25:22', 'Standardowe 3:0');

    $setterA = Player::factory()->make(['id' => 20, 'name' => 'Setter A', 'position' => PlayerPosition::Setter, 'training_bar' => 10]);
    $setterB = Player::factory()->make(['id' => 21, 'name' => 'Setter B', 'position' => PlayerPosition::Setter, 'training_bar' => 12]);
    $oppositeA = Player::factory()->make(['id' => 22, 'name' => 'Opposite A', 'position' => PlayerPosition::Opposite, 'training_bar' => 14]);
    $oppositeB = Player::factory()->make(['id' => 23, 'name' => 'Opposite B', 'position' => PlayerPosition::Opposite, 'training_bar' => 16]);

    $rankedPlans = new TrainingOptimizerService(
        new TrainingGainCalculator,
        new SubstitutionPlanGenerator,
    )->optimize([
        ['slot_number' => 1, 'position' => PlayerPosition::Setter, 'players' => [$setterA, $setterB]],
        ['slot_number' => 2, 'position' => PlayerPosition::Opposite, 'players' => [$oppositeA, $oppositeB]],
    ], $scenario, 3);

    expect($rankedPlans)->toHaveCount(3);
});

test('training optimizer service limits candidate pool per position for performance', function () {
    $scenario = MatchScenario::fromInput('25:20, 25:18, 25:22', 'Standardowe 3:0');

    $setterA = Player::factory()->make(['id' => 30, 'name' => 'Setter A', 'position' => PlayerPosition::Setter, 'training_bar' => 0]);
    $setterB = Player::factory()->make(['id' => 31, 'name' => 'Setter B', 'position' => PlayerPosition::Setter, 'training_bar' => 10]);
    $setterC = Player::factory()->make(['id' => 32, 'name' => 'Setter C', 'position' => PlayerPosition::Setter, 'training_bar' => 20]);
    $setterD = Player::factory()->make(['id' => 33, 'name' => 'Setter D', 'position' => PlayerPosition::Setter, 'training_bar' => 95]);
    $oppositeA = Player::factory()->make(['id' => 34, 'name' => 'Opposite A', 'position' => PlayerPosition::Opposite, 'training_bar' => 0]);
    $oppositeB = Player::factory()->make(['id' => 35, 'name' => 'Opposite B', 'position' => PlayerPosition::Opposite, 'training_bar' => 0]);

    $rankedPlans = (new TrainingOptimizerService(
        new TrainingGainCalculator,
        new SubstitutionPlanGenerator,
    ))->optimize([
        ['slot_number' => 1, 'position' => PlayerPosition::Setter, 'players' => [$setterA, $setterB, $setterC, $setterD]],
        ['slot_number' => 2, 'position' => PlayerPosition::Opposite, 'players' => [$oppositeA, $oppositeB]],
    ], $scenario, 5);

    $setterNames = collect($rankedPlans)
        ->flatMap(fn (array $plan): array => $plan['player_results'])
        ->where('position', PlayerPosition::Setter->value)
        ->pluck('name')
        ->unique()
        ->values()
        ->all();

    expect($setterNames)->not->toContain('Setter D');
});
