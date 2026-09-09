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
