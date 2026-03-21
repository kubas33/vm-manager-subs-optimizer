<?php

use App\MatchScenario;

test('match scenario parses sets and aggregates actions', function () {
    $scenario = MatchScenario::fromInput('25:21, 22:25, 25:20, 25:19', 'Standardowe 3:1');

    expect($scenario->label)->toBe('Standardowe 3:1')
        ->and($scenario->input)->toBe('25:21, 22:25, 25:20, 25:19')
        ->and($scenario->setsCount())->toBe(4)
        ->and($scenario->totalActions())->toBe(182)
        ->and($scenario->toArray())->toMatchArray([
            'label' => 'Standardowe 3:1',
            'sets_count' => 4,
            'total_actions' => 182,
        ]);
});

test('match scenario rejects invalid number of sets', function () {
    MatchScenario::fromInput('25:20, 25:18', 'Za krótki mecz');
})->throws(InvalidArgumentException::class, 'Scenariusz musi zawierać od 3 do 5 setów.');

test('match scenario rejects invalid set format', function () {
    MatchScenario::fromInput('25:20, abc, 25:18', 'Błędny mecz');
})->throws(InvalidArgumentException::class, 'Każdy set musi mieć format `25:20`.');

test('match scenario rejects empty input', function () {
    MatchScenario::fromInput('   ', 'Pusty mecz');
})->throws(InvalidArgumentException::class, 'Scenariusz nie może być pusty.');
