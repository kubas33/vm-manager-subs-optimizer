<?php

use App\Enums\PlayerPosition;
use App\MatchScenario;
use App\Models\Player;
use App\VmSubstitutionService;
use Livewire\Livewire;

test('selecting a variant keeps its lineup and bench visible', function () {
    foreach ([[PlayerPosition::Setter, 101], [PlayerPosition::Libero, 102], [PlayerPosition::Opposite, 103]] as [$position, $vmPlayerId]) {
        Player::factory()->forPosition($position)->withVmPlayerId($vmPlayerId)->create();
    }

    Player::factory()->count(7)->forPosition(PlayerPosition::MiddleBlocker)->sequence(fn ($sequence) => ['vm_player_id' => 200 + $sequence->index, 'training_bar' => $sequence->index * 5])->create();
    Player::factory()->count(5)->forPosition(PlayerPosition::OutsideHitter)->sequence(fn ($sequence) => ['vm_player_id' => 300 + $sequence->index, 'training_bar' => $sequence->index * 5])->create();

    session()->put('optimizer.input', [
        'positions' => [
            ['value' => PlayerPosition::MiddleBlocker->value, 'label' => 'Środkowy', 'active_players' => 7],
            ['value' => PlayerPosition::MiddleBlocker->value, 'label' => 'Środkowy', 'active_players' => 7],
            ['value' => PlayerPosition::OutsideHitter->value, 'label' => 'Przyjmujący', 'active_players' => 5],
        ],
        'fairness_threshold' => 20,
        'reserve_pools' => [
            ['position' => PlayerPosition::MiddleBlocker->value, 'position_label' => 'Środkowy', 'reserve_limit' => 4],
            ['position' => PlayerPosition::OutsideHitter->value, 'position_label' => 'Przyjmujący', 'reserve_limit' => 1],
        ],
        'scenarios' => [MatchScenario::fromInput('25:23, 22:25, 25:21, 20:25, 15:12', 'Trudne 3:2')->toArray()],
    ]);

    $component = Livewire::test('pages::optimizer.result');
    $scenario = $component->get('scenarioVariants')[0];
    $secondVariant = $scenario['variants'][1];

    $activeVariant = $component->get('activeVariant');

    expect(collect($activeVariant['lineup'])->pluck('source'))->toContain('optimized')
        ->and(collect($activeVariant['bench'])->filter())->not->toBeEmpty()
        ->and($activeVariant['is_sendable'])->toBeTrue()
        ->and($activeVariant['substitution_rules_count'])->toBe(count(app(VmSubstitutionService::class)->buildPayloads($activeVariant['plan'])));

    $component->call('selectVariant', $secondVariant['variant_key'])
        ->assertSet('selectedVariantKey', $secondVariant['variant_key'])
        ->assertSee('Wariant #2');

    expect($component->get('activeVariant')['variant_key'])->toBe($secondVariant['variant_key'])
        ->and(collect($component->get('activeVariant')['bench'])->filter())->not->toBeEmpty();

    $component->call('requestApplyVariant')
        ->assertSee('Reguły VM: '.$component->get('activeVariant')['substitution_rules_count'])
        ->assertSee('Zdarzenia w setach: '.$component->get('activeVariant')['substitutions_count']);
});

test('training losses and cap timing follow the selected scenario', function () {
    Player::factory()->forPosition(PlayerPosition::Setter)->create(['name' => 'Training Setter', 'training_bar' => 10]);

    session()->put('optimizer.input', [
        'positions' => [['value' => PlayerPosition::Setter->value, 'label' => 'Rozgrywający', 'active_players' => 1]],
        'fairness_threshold' => 20,
        'reserve_pools' => [['position' => PlayerPosition::Setter->value, 'position_label' => 'Rozgrywający', 'reserve_limit' => 0]],
        'scenarios' => [
            MatchScenario::fromInput('25:12, 25:14, 25:13', 'First')->toArray(),
            MatchScenario::fromInput('26:24, 25:0, 25:0', 'Second')->toArray(),
        ],
    ]);

    $component = Livewire::test('pages::optimizer.result')
        ->assertSee('Straty')
        ->assertSee('Straty w secie: 26')
        ->assertSee('Training Setter: 38 strat')
        ->assertSee('Limit +50 osiągnięty w secie 2')
        ->assertSee('Teoretyczne minimum strat: 64')
        ->assertSee('Straty ponad minimum: 0');

    $second = $component->get('scenarioVariants')[1];
    $component->call('selectScenario', $second['scenario_key'])
        ->assertSee('Limit +50 osiągnięty w secie 1')
        ->assertDontSee('Limit +50 osiągnięty w secie 2')
        ->assertSee('Teoretyczne minimum strat: 50')
        ->assertDontSee('Training Setter: 38 strat');
});

test('an automatically improved variant explains its gain and remains sendable', function () {
    foreach ([PlayerPosition::Setter, PlayerPosition::Setter, PlayerPosition::Opposite, PlayerPosition::MiddleBlocker, PlayerPosition::MiddleBlocker, PlayerPosition::OutsideHitter, PlayerPosition::OutsideHitter, PlayerPosition::Libero] as $index => $position) {
        Player::factory()->forPosition($position)->withVmPlayerId(500 + $index)->create(['name' => 'Player '.$index, 'training_bar' => 0]);
    }

    session()->put('optimizer.input', [
        'positions' => collect([PlayerPosition::Setter, PlayerPosition::Opposite, PlayerPosition::MiddleBlocker, PlayerPosition::MiddleBlocker])->map(fn (PlayerPosition $position): array => ['value' => $position->value, 'label' => $position->label()])->all(),
        'fairness_threshold' => 20,
        'reserve_pools' => collect([PlayerPosition::Setter, PlayerPosition::Opposite, PlayerPosition::MiddleBlocker])->map(fn (PlayerPosition $position): array => ['position' => $position->value, 'position_label' => $position->label(), 'reserve_limit' => $position === PlayerPosition::Setter ? 1 : 0])->all(),
        'scenarios' => [MatchScenario::fromInput('25:12, 25:14, 25:13', '3:0')->toArray()],
    ]);

    $component = Livewire::test('pages::optimizer.result')
        ->assertSee('Automatyczna korekta planu: +2 treningu i 2 mniej straconych akcji.');
    $variant = $component->get('activeVariant');

    expect($variant['total_gained_training'])->toBe(241)
        ->and($variant['is_sendable'])->toBeTrue()
        ->and($variant['send_blockers'])->toBe([])
        ->and(app(VmSubstitutionService::class)->buildPayloads($variant['plan']))->not->toBeEmpty();
});
