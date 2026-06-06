<?php

use App\Enums\PlayerPosition;
use App\Models\Player;

test('player attributes are cast to domain types', function () {
    $player = Player::factory()->create([
        'vm_player_id' => 2060721,
        'position' => PlayerPosition::MiddleBlocker,
        'training_bar' => 68,
        'active' => false,
    ]);

    expect($player->fresh())
        ->vm_player_id->toBe(2060721)
        ->position->toBe(PlayerPosition::MiddleBlocker)
        ->training_bar->toBe(68)
        ->active->toBeFalse();
});

test('player exposes training bar helper methods for optimization', function () {
    $player = Player::factory()->make([
        'training_bar' => 83,
    ]);

    expect($player->remainingTrainingCapacity())->toBe(17)
        ->and($player->maxTrainingGainPerMatch())->toBe(17)
        ->and($player->projectedTrainingBar(30))->toBe(100)
        ->and($player->wastedTrainingActions(30))->toBe(13);
});

test('active scope returns only active players', function () {
    $activePlayer = Player::factory()->create();
    $inactivePlayer = Player::factory()->inactive()->create();

    $playerIds = Player::query()->active()->pluck('id');

    expect($playerIds)
        ->toContain($activePlayer->id)
        ->not->toContain($inactivePlayer->id);
});
