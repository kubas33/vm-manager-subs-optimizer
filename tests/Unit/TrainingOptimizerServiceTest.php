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
        ['slot_number' => 1, 'position' => PlayerPosition::Setter, 'reserve_limit' => 1, 'players' => [$setterA, $setterB]],
        ['slot_number' => 2, 'position' => PlayerPosition::Opposite, 'reserve_limit' => 1, 'players' => [$oppositeA, $oppositeB]],
    ], $scenario, 5);

    expect($rankedPlans)->toHaveCount(5)
        ->and($rankedPlans[0]['total_gained_training'])->toBe(150)
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
        ['slot_number' => 1, 'position' => PlayerPosition::Setter, 'reserve_limit' => 1, 'players' => [$setterA, $setterB]],
        ['slot_number' => 2, 'position' => PlayerPosition::Opposite, 'reserve_limit' => 1, 'players' => [$oppositeA, $oppositeB]],
    ], $scenario, 20);

    expect($rankedPlans[0]['total_gained_training'])->toBe(6)
        ->and($rankedPlans[0]['final_training_bar_sum'])->toBe(6)
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
        ['slot_number' => 1, 'position' => PlayerPosition::Setter, 'reserve_limit' => 1, 'players' => [$setterA, $setterB]],
        ['slot_number' => 2, 'position' => PlayerPosition::Opposite, 'reserve_limit' => 1, 'players' => [$oppositeA, $oppositeB]],
    ], $scenario, 3);

    expect($rankedPlans)->toHaveCount(3);
});

test('training optimizer service limits candidate pool separately for different positions', function () {
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
        ['slot_number' => 1, 'position' => PlayerPosition::Setter, 'reserve_limit' => 2, 'players' => [$setterA, $setterB, $setterC, $setterD]],
        ['slot_number' => 2, 'position' => PlayerPosition::Opposite, 'reserve_limit' => 1, 'players' => [$oppositeA, $oppositeB]],
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

test('training optimizer service uses shared reserve pool for duplicate positions and keeps lowest bars', function () {
    $scenario = MatchScenario::fromInput('25:20, 25:18, 25:22', 'Standardowe 3:0');

    $middleA = Player::factory()->make(['id' => 40, 'name' => 'Middle A', 'position' => PlayerPosition::MiddleBlocker, 'training_bar' => 5]);
    $middleB = Player::factory()->make(['id' => 41, 'name' => 'Middle B', 'position' => PlayerPosition::MiddleBlocker, 'training_bar' => 10]);
    $middleC = Player::factory()->make(['id' => 42, 'name' => 'Middle C', 'position' => PlayerPosition::MiddleBlocker, 'training_bar' => 15]);
    $middleD = Player::factory()->make(['id' => 43, 'name' => 'Middle D', 'position' => PlayerPosition::MiddleBlocker, 'training_bar' => 20]);
    $middleE = Player::factory()->make(['id' => 44, 'name' => 'Middle E', 'position' => PlayerPosition::MiddleBlocker, 'training_bar' => 80]);

    $rankedPlans = (new TrainingOptimizerService(
        new TrainingGainCalculator,
        new SubstitutionPlanGenerator,
    ))->optimize([
        ['slot_number' => 1, 'position' => PlayerPosition::MiddleBlocker, 'reserve_limit' => 2, 'players' => [$middleA, $middleB, $middleC, $middleD, $middleE]],
        ['slot_number' => 2, 'position' => PlayerPosition::MiddleBlocker, 'reserve_limit' => 2, 'players' => [$middleA, $middleB, $middleC, $middleD, $middleE]],
    ], $scenario, 5);

    $middleNames = collect($rankedPlans)
        ->flatMap(fn (array $plan): array => $plan['player_results'])
        ->where('position', PlayerPosition::MiddleBlocker->value)
        ->pluck('name')
        ->unique()
        ->values()
        ->all();

    expect($middleNames)
        ->toContain('Middle A', 'Middle B', 'Middle C', 'Middle D')
        ->not->toContain('Middle E');
});

test('training optimizer service maximizes total gained training across shared candidate pool', function () {
    $scenario = MatchScenario::fromInput('25:0, 25:0, 25:0', 'Łatwe 3:0');

    $middleA = Player::factory()->make(['id' => 50, 'name' => 'Middle A', 'position' => PlayerPosition::MiddleBlocker, 'training_bar' => 0]);
    $middleB = Player::factory()->make(['id' => 51, 'name' => 'Middle B', 'position' => PlayerPosition::MiddleBlocker, 'training_bar' => 0]);
    $middleC = Player::factory()->make(['id' => 52, 'name' => 'Middle C', 'position' => PlayerPosition::MiddleBlocker, 'training_bar' => 0]);
    $middleD = Player::factory()->make(['id' => 53, 'name' => 'Middle D', 'position' => PlayerPosition::MiddleBlocker, 'training_bar' => 0]);

    $rankedPlans = (new TrainingOptimizerService(
        new TrainingGainCalculator,
        new SubstitutionPlanGenerator,
    ))->optimize([
        ['slot_number' => 1, 'position' => PlayerPosition::MiddleBlocker, 'reserve_limit' => 2, 'players' => [$middleA, $middleB, $middleC, $middleD]],
        ['slot_number' => 2, 'position' => PlayerPosition::MiddleBlocker, 'reserve_limit' => 2, 'players' => [$middleA, $middleB, $middleC, $middleD]],
    ], $scenario, 1);

    expect($rankedPlans[0]['total_gained_training'])->toBeGreaterThan(100)
        ->and($rankedPlans[0]['substitutions_count'])->toBeGreaterThan(0)
        ->and(collect($rankedPlans[0]['player_results'])->where('played_actions', '>', 0)->count())->toBeGreaterThan(2);
});

test('training optimizer service rotates reserves when shared pool is large', function () {
    $scenario = MatchScenario::fromInput('25:20, 25:18, 25:22', 'Standardowe 3:0');

    $middleA = Player::factory()->make(['id' => 60, 'name' => 'Middle A', 'position' => PlayerPosition::MiddleBlocker, 'training_bar' => 0]);
    $middleB = Player::factory()->make(['id' => 61, 'name' => 'Middle B', 'position' => PlayerPosition::MiddleBlocker, 'training_bar' => 3]);
    $middleC = Player::factory()->make(['id' => 62, 'name' => 'Middle C', 'position' => PlayerPosition::MiddleBlocker, 'training_bar' => 14]);
    $middleD = Player::factory()->make(['id' => 63, 'name' => 'Middle D', 'position' => PlayerPosition::MiddleBlocker, 'training_bar' => 38]);
    $middleE = Player::factory()->make(['id' => 64, 'name' => 'Middle E', 'position' => PlayerPosition::MiddleBlocker, 'training_bar' => 46]);
    $middleF = Player::factory()->make(['id' => 65, 'name' => 'Middle F', 'position' => PlayerPosition::MiddleBlocker, 'training_bar' => 52]);
    $middleG = Player::factory()->make(['id' => 66, 'name' => 'Middle G', 'position' => PlayerPosition::MiddleBlocker, 'training_bar' => 53]);

    $rankedPlans = (new TrainingOptimizerService(
        new TrainingGainCalculator,
        new SubstitutionPlanGenerator,
    ))->optimize([
        ['slot_number' => 1, 'position' => PlayerPosition::MiddleBlocker, 'reserve_limit' => 5, 'players' => [$middleA, $middleB, $middleC, $middleD, $middleE, $middleF, $middleG]],
        ['slot_number' => 2, 'position' => PlayerPosition::MiddleBlocker, 'reserve_limit' => 5, 'players' => [$middleA, $middleB, $middleC, $middleD, $middleE, $middleF, $middleG]],
    ], $scenario, 5);

    $lowestFinalTrainingBar = collect($rankedPlans[0]['player_results'])
        ->min('final_training_bar');

    expect($rankedPlans[0]['total_gained_training'])->toBeGreaterThan(150)
        ->and($rankedPlans[0]['substitutions_count'])->toBeGreaterThan(0)
        ->and(count($rankedPlans))->toBeGreaterThan(1)
        ->and(count($rankedPlans[0]['player_results']))->toBe(7)
        ->and($lowestFinalTrainingBar)->toBeGreaterThan(40);
});

test('training optimizer service prefers plans with fewer players below fairness threshold when total gain is tied', function () {
    $service = new TrainingOptimizerService(
        new TrainingGainCalculator,
        new SubstitutionPlanGenerator,
    );

    $comparator = Closure::bind(
        fn (array $left, array $right): int => $this->compareRankedPlans($left, $right),
        $service,
        TrainingOptimizerService::class,
    );

    $leftPlan = [
        'total_gained_training' => 100,
        'players_below_fairness_threshold' => 1,
        'player_results' => [
            ['final_training_bar' => 10],
            ['final_training_bar' => 55],
        ],
        'wasted_actions' => 12,
        'substitutions_count' => 3,
    ];

    $rightPlan = [
        'total_gained_training' => 100,
        'players_below_fairness_threshold' => 0,
        'player_results' => [
            ['final_training_bar' => 25],
            ['final_training_bar' => 40],
        ],
        'wasted_actions' => 99,
        'substitutions_count' => 8,
    ];

    expect($comparator($leftPlan, $rightPlan))->toBeGreaterThan(0);
});

test('training optimizer service aggregates scenario evaluations across multiple scenarios', function () {
    $service = new TrainingOptimizerService(
        new TrainingGainCalculator,
        new SubstitutionPlanGenerator,
    );

    $aggregate = Closure::bind(
        fn (array $evaluations): array => $this->aggregateScenarioEvaluations($evaluations),
        $service,
        TrainingOptimizerService::class,
    );

    $firstEvaluation = [
        'total_gained_training' => 100,
        'final_training_bar_sum' => 72,
        'players_below_fairness_threshold' => 1,
        'lowest_final_training_bar' => 12,
        'wasted_actions' => 18,
        'substitutions_count' => 2,
        'fairness_threshold' => 20,
        'player_results' => [
            [
                'id' => 1,
                'name' => 'Ervin Rapčan',
                'position' => PlayerPosition::MiddleBlocker->value,
                'position_label' => PlayerPosition::MiddleBlocker->label(),
                'training_bar' => 0,
                'starting_training_bar' => 0,
                'played_actions' => 40,
                'gained_training' => 40,
                'final_training_bar' => 12,
                'wasted_actions' => 0,
            ],
            [
                'id' => 2,
                'name' => 'Kacper Kwiatek',
                'position' => PlayerPosition::MiddleBlocker->value,
                'position_label' => PlayerPosition::MiddleBlocker->label(),
                'training_bar' => 3,
                'starting_training_bar' => 3,
                'played_actions' => 20,
                'gained_training' => 20,
                'final_training_bar' => 60,
                'wasted_actions' => 0,
            ],
        ],
        'plan' => ['slots' => []],
    ];

    $secondEvaluation = [
        'total_gained_training' => 120,
        'final_training_bar_sum' => 100,
        'players_below_fairness_threshold' => 1,
        'lowest_final_training_bar' => 18,
        'wasted_actions' => 12,
        'substitutions_count' => 3,
        'fairness_threshold' => 20,
        'player_results' => [
            [
                'id' => 1,
                'name' => 'Ervin Rapčan',
                'position' => PlayerPosition::MiddleBlocker->value,
                'position_label' => PlayerPosition::MiddleBlocker->label(),
                'training_bar' => 0,
                'starting_training_bar' => 0,
                'played_actions' => 50,
                'gained_training' => 50,
                'final_training_bar' => 28,
                'wasted_actions' => 0,
            ],
            [
                'id' => 2,
                'name' => 'Kacper Kwiatek',
                'position' => PlayerPosition::MiddleBlocker->value,
                'position_label' => PlayerPosition::MiddleBlocker->label(),
                'training_bar' => 3,
                'starting_training_bar' => 3,
                'played_actions' => 30,
                'gained_training' => 30,
                'final_training_bar' => 50,
                'wasted_actions' => 0,
            ],
        ],
        'plan' => ['slots' => []],
    ];

    $aggregated = $aggregate([$firstEvaluation, $secondEvaluation]);

    expect($aggregated['scenario_count'])->toBe(2)
        ->and($aggregated['total_gained_training'])->toBe(220)
        ->and($aggregated['players_below_fairness_threshold'])->toBe(2)
        ->and($aggregated['lowest_final_training_bar'])->toBe(12)
        ->and($aggregated['wasted_actions'])->toBe(30)
        ->and($aggregated['substitutions_count'])->toBe(5)
        ->and(collect($aggregated['player_results'])->pluck('final_training_bar')->all())->toBe([20, 55]);
});
