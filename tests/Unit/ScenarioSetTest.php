<?php

use App\MatchScenario;
use App\ScenarioSet;

test('scenario set aggregates multiple scenarios', function () {
    $scenarioSet = ScenarioSet::fromInputs([
        '25:20, 25:18, 25:22',
        '25:21, 22:25, 25:20, 25:19',
    ]);

    expect($scenarioSet->count())->toBe(2)
        ->and($scenarioSet->totalActions())->toBe(317)
        ->and($scenarioSet->toArray())->toHaveCount(2)
        ->and($scenarioSet->toArray()[0])->toMatchArray([
            'label' => 'Scenariusz 1',
            'sets_count' => 3,
            'total_actions' => 135,
        ])
        ->and($scenarioSet->toArray()[1])->toMatchArray([
            'label' => 'Scenariusz 2',
            'sets_count' => 4,
            'total_actions' => 182,
        ]);
});

test('scenario set can wrap a single scenario', function () {
    $scenarioSet = ScenarioSet::single(
        MatchScenario::fromInput('25:23, 22:25, 25:21, 20:25, 15:12', 'Trudne 3:2'),
    );

    expect($scenarioSet->count())->toBe(1)
        ->and($scenarioSet->totalActions())->toBe(213);
});

test('scenario set rejects empty collection', function () {
    new ScenarioSet([]);
})->throws(InvalidArgumentException::class, 'Wpisz co najmniej jeden scenariusz.');
