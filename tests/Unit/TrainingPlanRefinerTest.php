<?php

use App\Enums\PlayerPosition;
use App\MatchScenario;
use App\Models\Player;
use App\SubstitutionPlanGenerator;
use App\TrainingPlanRefiner;

function refinementFixture(): array
{
    $players = collect([1, 2])->map(fn (int $id): Player => Player::factory()->make([
        'id' => $id, 'name' => 'Player '.$id, 'position' => PlayerPosition::Setter, 'training_bar' => 0,
    ]))->all();
    $scenario = MatchScenario::fromInput('25:12, 25:14, 25:13', '3:0');
    $slots = [['slot_number' => 1, 'position' => PlayerPosition::Setter, 'reserve_limit' => 1, 'players' => $players]];
    $plan = (new SubstitutionPlanGenerator)->generate([
        [...$slots[0], 'players' => [$players[0]]],
    ], $scenario)[0];

    return [$plan, $slots, $scenario];
}

test('refinement improves a complete match by moving substitutions between sets', function () {
    [$plan, $slots, $scenario] = refinementFixture();

    $result = (new TrainingPlanRefiner(new SubstitutionPlanGenerator))->refine($plan, $slots, [$scenario]);

    expect($result['gained_training_before'])->toBe(50)
        ->and($result['gained_training_after'])->toBe(91)
        ->and(array_column(array_column($result['plan']['slots'][0]['sets'], 'active_player'), 'id'))->toBe([2, 1, 2])
        ->and($result['plan']['slots'][0]['starter']['id'])->toBe(1)
        ->and($result['plan']['slots'][0]['sets'][2]['description'])->toContain('Set 3');

    $again = (new TrainingPlanRefiner(new SubstitutionPlanGenerator))->refine($result['plan'], $slots, [$scenario]);
    expect($again['plan'])->toBe($result['plan']);
});

test('refinement preserves a plan without reserves', function () {
    [$plan, $slots, $scenario] = refinementFixture();
    $slots[0]['players'] = [$slots[0]['players'][0]];
    $slots[0]['reserve_limit'] = 0;

    $result = (new TrainingPlanRefiner(new SubstitutionPlanGenerator))->refine($plan, $slots, [$scenario]);

    expect($result['plan'])->toBe($plan)
        ->and($result['gained_training_after'])->toBe(50);
});

test('refinement scores all scenarios and respects worst case mode', function (bool $safeMode, int $before, int $after) {
    [$plan, $slots, $scenario] = refinementFixture();
    $shortScenario = MatchScenario::fromInput('25:0, 25:0, 25:0', 'Short');

    $result = (new TrainingPlanRefiner(new SubstitutionPlanGenerator))->refine($plan, $slots, [$scenario, $shortScenario], $safeMode);

    expect($result['gained_training_before'])->toBe($before)
        ->and($result['gained_training_after'])->toBe($after);
})->with([[false, 100, 166], [true, 50, 75]]);

test('refinement shares one reserve legally across two slots', function () {
    $players = collect([1, 2, 3])->map(fn (int $id): Player => Player::factory()->make([
        'id' => $id, 'name' => 'Middle '.$id, 'position' => PlayerPosition::MiddleBlocker, 'training_bar' => 0,
    ]))->all();
    $scenario = MatchScenario::fromInput('25:12, 25:14, 25:13', '3:0');
    $slots = collect([1, 2])->map(fn (int $slot): array => [
        'slot_number' => $slot, 'position' => PlayerPosition::MiddleBlocker, 'reserve_limit' => 1, 'players' => $players,
    ])->all();
    $plan = (new SubstitutionPlanGenerator)->generate(array_map(fn (array $slot): array => [
        ...$slot, 'players' => array_slice($players, 0, 2),
    ], $slots), $scenario)[0];

    $result = (new TrainingPlanRefiner(new SubstitutionPlanGenerator))->refine($plan, $slots, [$scenario]);

    expect($result['gained_training_before'])->toBe(100)
        ->and($result['gained_training_after'])->toBe(150);

    foreach ($scenario->sets as $index => $set) {
        $activeIds = array_map(fn (array $slot): int => $slot['sets'][$index]['active_player']['id'], $result['plan']['slots']);
        expect(array_unique($activeIds))->toHaveCount(2);
    }

    $reserveIds = collect($result['plan']['slots'])->flatMap(fn (array $slot): array => $slot['sets'])
        ->pluck('substitution_player.id')->filter()->unique()->values()->all();
    expect($reserveIds)->toBe([3]);
});
