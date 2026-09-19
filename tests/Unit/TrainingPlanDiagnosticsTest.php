<?php

use App\Enums\PlayerPosition;
use App\MatchScenario;
use App\Models\Player;
use App\SubstitutionPlanGenerator;
use App\TrainingPlanDiagnostics;

test('diagnostics splits wasted actions by set and identifies when the actual training cap is reached', function (int $bar, array $waste, ?int $limitSet, int $minimum) {
    $player = Player::factory()->make(['id' => 1, 'name' => 'Starter', 'position' => PlayerPosition::Setter, 'training_bar' => $bar]);
    $slots = [['slot_number' => 1, 'position' => PlayerPosition::Setter, 'reserve_limit' => 0, 'players' => [$player]]];
    $scenario = MatchScenario::fromInput('25:12, 25:14, 25:13', '3:0');
    $plan = (new SubstitutionPlanGenerator)->generate($slots, $scenario)[0];

    $result = (new TrainingPlanDiagnostics)->analyze($plan, $slots, $scenario);

    expect(array_column($result['sets'], 'wasted_actions'))->toBe($waste)
        ->and($result['players'][1]['limit_reached_set'])->toBe($limitSet)
        ->and($result['players'][1]['wasted_actions'])->toBe($minimum)
        ->and($result['minimum_wasted_actions'])->toBe($minimum)
        ->and($result['excess_wasted_actions'])->toBe(0);
})->with([
    'match cap' => [10, [0, 26, 38], 2, 64],
    'bar cap' => [80, [17, 39, 38], 1, 94],
    'already full' => [100, [37, 39, 38], 0, 114],
]);

test('diagnostics accounts for the starters first action and a reserves cumulative limit', function () {
    $starter = Player::factory()->make(['id' => 1, 'name' => 'Starter', 'position' => PlayerPosition::Setter, 'training_bar' => 10]);
    $reserve = Player::factory()->make(['id' => 2, 'name' => 'Reserve', 'position' => PlayerPosition::Setter, 'training_bar' => 80]);
    $slots = [['slot_number' => 1, 'position' => PlayerPosition::Setter, 'reserve_limit' => 1, 'players' => [$starter, $reserve]]];
    $scenario = MatchScenario::fromInput('25:12, 25:14, 25:13', '3:0');
    $plan = collect((new SubstitutionPlanGenerator)->generate($slots, $scenario))->first(fn (array $plan): bool => $plan['slots'][0]['starter']['id'] === 1
        && array_column(array_column($plan['slots'][0]['sets'], 'active_player'), 'id') === [1, 2, 2]
    );

    $result = (new TrainingPlanDiagnostics)->analyze($plan, $slots, $scenario);

    expect($result['players'][1]['played_actions'])->toBe(39)
        ->and($result['players'][1]['limit_reached_set'])->toBeNull()
        ->and($result['players'][2]['played_actions'])->toBe(75)
        ->and($result['players'][2]['capacity'])->toBe(20)
        ->and($result['players'][2]['limit_reached_set'])->toBe(2)
        ->and(array_column($result['sets'], 'wasted_actions'))->toBe([0, 18, 37])
        ->and($result['minimum_wasted_actions'])->toBe(44)
        ->and($result['excess_wasted_actions'])->toBe(11);
});
