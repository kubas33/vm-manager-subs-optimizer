<?php

use App\Enums\PlayerPosition;
use App\LineupRecommendationService;
use App\Models\Player;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function createFullLineupRoster(): void
{
    Player::factory()->forPosition(PlayerPosition::Opposite)->create([
        'name' => 'Opposite Low',
        'training_bar' => 5,
    ]);
    Player::factory()->forPosition(PlayerPosition::Opposite)->create([
        'name' => 'Opposite High',
        'training_bar' => 40,
    ]);

    Player::factory()->forPosition(PlayerPosition::MiddleBlocker)->create([
        'name' => 'Middle Low',
        'training_bar' => 8,
    ]);
    Player::factory()->forPosition(PlayerPosition::MiddleBlocker)->create([
        'name' => 'Middle High',
        'training_bar' => 22,
    ]);
    Player::factory()->forPosition(PlayerPosition::MiddleBlocker)->create([
        'name' => 'Middle Reserve',
        'training_bar' => 35,
    ]);

    Player::factory()->forPosition(PlayerPosition::OutsideHitter)->create([
        'name' => 'Outside Low',
        'training_bar' => 10,
    ]);
    Player::factory()->forPosition(PlayerPosition::OutsideHitter)->create([
        'name' => 'Outside Mid',
        'training_bar' => 18,
    ]);
    Player::factory()->forPosition(PlayerPosition::OutsideHitter)->create([
        'name' => 'Outside High',
        'training_bar' => 30,
    ]);

    Player::factory()->forPosition(PlayerPosition::Setter)->create([
        'name' => 'Setter Low',
        'training_bar' => 12,
    ]);
    Player::factory()->forPosition(PlayerPosition::Setter)->create([
        'name' => 'Setter High',
        'training_bar' => 45,
    ]);

    Player::factory()->forPosition(PlayerPosition::Libero)->create([
        'name' => 'Libero Low',
        'training_bar' => 7,
    ]);
    Player::factory()->forPosition(PlayerPosition::Libero)->create([
        'name' => 'Libero High',
        'training_bar' => 50,
    ]);
}

test('lineup recommendation service builds complete primary lineup with lowest bars', function () {
    createFullLineupRoster();

    $result = (new LineupRecommendationService)->recommend();

    expect($result['is_complete'])->toBeTrue()
        ->and($result['missing_slots'])->toBe([])
        ->and($result['recommendations'][0]['kind'])->toBe('primary');

    $primary = $result['recommendations'][0];

    expect($primary['kind'])->toBe('primary')
        ->and($primary['total_training_bar'])->toBe(82)
        ->and($primary['swap_description'])->toBeNull()
        ->and($primary['changed_slot_keys'])->toBe([]);

    $playersBySlot = collect($primary['slots'])->pluck('player.name', 'key');

    expect($playersBySlot['opposite'])->toBe('Opposite Low')
        ->and($playersBySlot['middle_1'])->toBe('Middle Low')
        ->and($playersBySlot['middle_2'])->toBe('Middle High')
        ->and($playersBySlot['outside_1'])->toBe('Outside Low')
        ->and($playersBySlot['outside_2'])->toBe('Outside Mid')
        ->and($playersBySlot['setter'])->toBe('Setter Low')
        ->and($playersBySlot['libero'])->toBe('Libero Low');
});

test('lineup recommendation service provides at most three multi change alternatives', function () {
    createFullLineupRoster();

    $result = (new LineupRecommendationService)->recommend();
    $alternatives = collect($result['recommendations'])->where('kind', 'alternative')->values();

    expect($alternatives)->toHaveCount(LineupRecommendationService::ALTERNATIVE_COUNT);

    foreach ($alternatives as $alternative) {
        expect($alternative['changed_slot_keys'])->not->toBeEmpty()
            ->and(count($alternative['changed_slot_keys']))->toBeGreaterThanOrEqual(1)
            ->and($alternative['swap_description'])->toContain('zmiany:');
    }

    expect($alternatives[0]['total_training_bar'])->toBeGreaterThanOrEqual($result['recommendations'][0]['total_training_bar']);
});

test('lineup recommendation service prefers alternatives with multiple changes', function () {
    createFullLineupRoster();

    $result = (new LineupRecommendationService)->recommend();
    $alternatives = collect($result['recommendations'])->where('kind', 'alternative')->values();

    expect($alternatives->contains(fn (array $alternative): bool => count($alternative['changed_slot_keys']) >= 2))->toBeTrue();
});

test('lineup recommendation service reports missing slots for incomplete roster', function () {
    Player::factory()->forPosition(PlayerPosition::MiddleBlocker)->create([
        'name' => 'Only Middle',
        'training_bar' => 10,
    ]);

    $result = (new LineupRecommendationService)->recommend();

    expect($result['is_complete'])->toBeFalse()
        ->and($result['missing_slots'])->not->toBeEmpty()
        ->and(collect($result['missing_slots'])->pluck('slot'))->toContain('middle_2', 'opposite');
});

test('lineup recommendation service includes similar high bar swaps when both players are at or above sixty percent', function () {
    Player::factory()->forPosition(PlayerPosition::Opposite)->create([
        'name' => 'Opposite Starter',
        'training_bar' => 72,
    ]);
    Player::factory()->forPosition(PlayerPosition::Opposite)->create([
        'name' => 'Opposite Similar',
        'training_bar' => 75,
    ]);

    foreach ([PlayerPosition::MiddleBlocker, PlayerPosition::OutsideHitter] as $position) {
        Player::factory()->forPosition($position)->count(2)->create(['training_bar' => 70]);
    }

    Player::factory()->forPosition(PlayerPosition::Setter)->create(['training_bar' => 71]);
    Player::factory()->forPosition(PlayerPosition::Libero)->create(['training_bar' => 73]);

    $result = (new LineupRecommendationService)->recommend();
    $alternatives = collect($result['recommendations'])->where('kind', 'alternative')->values();

    expect($alternatives)->not->toBeEmpty()
        ->and($alternatives->first(fn (array $alternative): bool => str_contains($alternative['swap_description'], 'Opposite Similar')))->not->toBeNull();
});

test('lineup recommendation service excludes high bar swaps when bars are not similar', function () {
    Player::factory()->forPosition(PlayerPosition::Opposite)->create([
        'name' => 'Opposite Starter',
        'training_bar' => 65,
    ]);
    Player::factory()->forPosition(PlayerPosition::Opposite)->create([
        'name' => 'Opposite Far',
        'training_bar' => 90,
    ]);

    foreach ([PlayerPosition::MiddleBlocker, PlayerPosition::OutsideHitter] as $position) {
        Player::factory()->forPosition($position)->count(2)->create(['training_bar' => 70]);
    }

    Player::factory()->forPosition(PlayerPosition::Setter)->create(['training_bar' => 71]);
    Player::factory()->forPosition(PlayerPosition::Libero)->create(['training_bar' => 73]);

    $result = (new LineupRecommendationService)->recommend();
    $alternatives = collect($result['recommendations'])->where('kind', 'alternative')->values();

    expect($alternatives->contains(fn (array $alternative): bool => str_contains($alternative['swap_description'], 'Opposite Far')))->toBeFalse();
});

test('lineup recommendation service breaks ties by player name', function () {
    Player::factory()->forPosition(PlayerPosition::Opposite)->create([
        'name' => 'Zebra Opposite',
        'training_bar' => 10,
    ]);
    Player::factory()->forPosition(PlayerPosition::Opposite)->create([
        'name' => 'Alpha Opposite',
        'training_bar' => 10,
    ]);

    foreach ([PlayerPosition::MiddleBlocker, PlayerPosition::OutsideHitter] as $position) {
        Player::factory()->forPosition($position)->count(2)->create(['training_bar' => 10]);
    }

    Player::factory()->forPosition(PlayerPosition::Setter)->create(['training_bar' => 10]);
    Player::factory()->forPosition(PlayerPosition::Libero)->create(['training_bar' => 10]);

    $result = (new LineupRecommendationService)->recommend();
    $primary = $result['recommendations'][0];

    expect(collect($primary['slots'])->firstWhere('key', 'opposite')['player']->name)->toBe('Alpha Opposite');
});

test('lineup recommendation service returns empty recommendations when there are no active players', function () {
    Player::factory()->inactive()->count(3)->create();

    $result = (new LineupRecommendationService)->recommend();

    expect($result['recommendations'])->toBe([])
        ->and($result['is_complete'])->toBeFalse();
});

test('lineup recommendation service ignores inactive players', function () {
    createFullLineupRoster();

    Player::query()
        ->where('position', PlayerPosition::Opposite)
        ->where('name', 'Opposite Low')
        ->update(['active' => false]);

    $result = (new LineupRecommendationService)->recommend();
    $primary = collect($result['recommendations'][0]['slots'])->firstWhere('key', 'opposite');

    expect($primary['player']->name)->toBe('Opposite High');
});
