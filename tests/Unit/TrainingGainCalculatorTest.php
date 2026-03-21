<?php

use App\Enums\PlayerPosition;
use App\MatchScenario;
use App\Models\Player;
use App\TrainingGainCalculator;

test('training gain calculator uses played actions below match limit', function () {
    $player = Player::factory()->make([
        'position' => PlayerPosition::Setter,
        'training_bar' => 40,
    ]);

    $result = new TrainingGainCalculator()->calculate($player, 18);

    expect($result)->toBe([
        'starting_training_bar' => 40,
        'played_actions' => 18,
        'gained_training' => 18,
        'final_training_bar' => 58,
        'wasted_actions' => 0,
    ]);
});

test('training gain calculator caps training gain at 50 actions per match', function () {
    $player = Player::factory()->make([
        'position' => PlayerPosition::MiddleBlocker,
        'training_bar' => 10,
    ]);

    $result = new TrainingGainCalculator()->calculate($player, 70);

    expect($result)->toBe([
        'starting_training_bar' => 10,
        'played_actions' => 70,
        'gained_training' => 50,
        'final_training_bar' => 60,
        'wasted_actions' => 20,
    ]);
});

test('training gain calculator respects remaining capacity to 100 percent', function () {
    $player = Player::factory()->make([
        'position' => PlayerPosition::Opposite,
        'training_bar' => 84,
    ]);

    $result = new TrainingGainCalculator()->calculate($player, 30);

    expect($result)->toBe([
        'starting_training_bar' => 84,
        'played_actions' => 30,
        'gained_training' => 16,
        'final_training_bar' => 100,
        'wasted_actions' => 14,
    ]);
});

test('training gain calculator can calculate from a match scenario dto', function () {
    $player = Player::factory()->make([
        'position' => PlayerPosition::OutsideHitter,
        'training_bar' => 55,
    ]);

    $scenario = MatchScenario::fromInput('25:20, 25:18, 25:22', 'Standardowe 3:0');

    $result = new TrainingGainCalculator()->calculateForScenario($player, $scenario);

    expect($result)->toMatchArray([
        'played_actions' => 135,
        'gained_training' => 45,
        'final_training_bar' => 100,
        'wasted_actions' => 90,
    ]);
});
