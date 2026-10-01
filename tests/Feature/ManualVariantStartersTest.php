<?php

use App\Enums\PlayerPosition;
use App\MatchScenario;
use App\Models\Player;
use App\VmSubstitutionService;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

function manualStarterComponent(): Testable
{
    foreach (PlayerPosition::cases() as $index => $position) {
        Player::factory()->forPosition($position)->withVmPlayerId(100 + $index)->create(['training_bar' => 0]);
        if (in_array($position, [PlayerPosition::MiddleBlocker, PlayerPosition::OutsideHitter], true)) {
            Player::factory()->forPosition($position)->withVmPlayerId(200 + $index)->create(['training_bar' => 0]);
        }
    }
    session()->put('optimizer.input', [
        'positions' => [['value' => PlayerPosition::Setter->value, 'label' => 'Rozgrywający']],
        'fairness_threshold' => 20,
        'reserve_pools' => [['position' => PlayerPosition::Setter->value, 'position_label' => 'Rozgrywający', 'reserve_limit' => 1]],
        'scenarios' => [MatchScenario::fromInput('25:20, 25:18, 25:22', 'Mecz')->toArray()],
    ]);

    return Livewire::test('pages::optimizer.result');
}

test('manual starter selects contain only available players at the court position', function () {
    $component = manualStarterComponent();
    $healthy = Player::factory()->forPosition(PlayerPosition::Opposite)->withVmPlayerId(301)->create(['training_bar' => 37]);
    $injured = Player::factory()->forPosition(PlayerPosition::Opposite)->create(['is_injured' => true]);
    $inactive = Player::factory()->forPosition(PlayerPosition::Opposite)->create(['active' => false]);
    $component->call('startManualEdit')->assertSee('manualAssignments.starter-opposite', false)
        ->assertSee($healthy->name.' · 37%');
    $options = collect($component->instance()->manualPlayerOptions(PlayerPosition::Opposite->value));

    expect($options->pluck('id'))->toContain($healthy->id)->not->toContain($injured->id, $inactive->id)
        ->and($options->every(fn (Player $player): bool => $player->position === PlayerPosition::Opposite))->toBeTrue();
});

test('base starter edits persist and cancel leaves the saved lineup intact', function () {
    $component = manualStarterComponent();
    $replacement = Player::factory()->forPosition(PlayerPosition::Opposite)->withVmPlayerId(401)->create(['training_bar' => 90]);
    $original = $component->get('activeVariant');
    $component->call('startManualEdit')->set('manualAssignments.starter-opposite', (string) $replacement->id);
    $preview = $component->get('manualPreview');
    expect($preview['is_sendable'])->toBeTrue()
        ->and($preview['lineup']['opposite']['player']->id)->toBe($replacement->id)
        ->and($preview['starter_vm_player_ids'])->toContain(401)
        ->and($preview['total_gained_training'])->toBe($original['total_gained_training']);
    $component->call('saveManualVariant')->assertHasNoErrors();
    $reloaded = Livewire::test('pages::optimizer.result');
    expect($reloaded->get('activeVariant')['lineup']['opposite']['player']->id)->toBe($replacement->id);
    $reloaded->call('startManualEdit')->set('manualAssignments.starter-opposite', (string) $original['lineup']['opposite']['player']->id)->call('cancelManualEdit');
    expect($reloaded->get('activeVariant')['lineup']['opposite']['player']->id)->toBe($replacement->id);
});

test('optimized starter remains fixed after recalculation and explicit reserve export', function () {
    $component = manualStarterComponent();
    $replacement = Player::factory()->forPosition(PlayerPosition::Setter)->withVmPlayerId(501)->create(['training_bar' => 90]);
    $reserve = Player::factory()->forPosition(PlayerPosition::Setter)->withVmPlayerId(502)->create(['training_bar' => 40]);
    $component->call('startManualEdit');
    $selectedBenchIds = collect($component->get('manualPreview')['bench'])->filter()->pluck('id')->all();
    $component->set('manualAssignments.starter-setter', (string) $replacement->id);
    $preview = $component->get('manualPreview');
    foreach ($preview['plan']['slots'][0]['sets'] as $set) {
        expect($set['starter_player']['id'])->toBe($replacement->id)
            ->and(in_array($set['active_player']['id'], [$replacement->id, ...$selectedBenchIds], true))->toBeTrue();
    }
    $component->set('manualAssignments.1-1', (string) $reserve->id);
    $preview = $component->get('manualPreview');
    $payloads = app(VmSubstitutionService::class)->buildPayloads($preview['plan']);
    expect($preview['is_sendable'])->toBeTrue()
        ->and($preview['plan']['slots'][0]['sets'][0]['substitution_player']['id'])->toBe($reserve->id)
        ->and(collect($payloads)->firstWhere('set1', 1)['playerOut'])->toBe(501)
        ->and(collect($payloads)->firstWhere('set1', 1)['playerIn'])->toBe(502);
    $component->call('saveManualVariant')->assertHasNoErrors();
    expect(Livewire::test('pages::optimizer.result')->get('activeVariant')['plan'])->toBe($preview['plan']);
});

test('invalid manual starters cannot be saved', function (string $kind) {
    $component = manualStarterComponent();
    $value = match ($kind) {
        'injured' => Player::factory()->forPosition(PlayerPosition::Opposite)->create(['is_injured' => true])->id,
        'inactive' => Player::factory()->forPosition(PlayerPosition::Opposite)->create(['active' => false])->id,
        'position' => Player::query()->where('position', PlayerPosition::Libero->value)->firstOrFail()->id,
        'missing' => 999999,
        'malformed' => '1garbage',
        default => '',
    };
    $component->call('startManualEdit')->set('manualAssignments.starter-opposite', (string) $value);
    expect($component->get('manualPreview')['is_sendable'])->toBeFalse();
    $component->call('saveManualVariant')->assertHasErrors('manual');
    expect($component->get('savedManualVariants'))->toBe([]);
})->with(['injured', 'inactive', 'position', 'missing', 'malformed', 'empty']);

test('manual duplicate base starters are blocked without replacing another court player', function () {
    $component = manualStarterComponent();
    $original = $component->get('activeVariant');
    $other = $original['lineup']['outside_2']['player'];
    $component->call('startManualEdit')->set('manualAssignments.starter-outside_1', (string) $other->id);
    $preview = $component->get('manualPreview');
    expect($preview['is_sendable'])->toBeFalse()
        ->and($preview['lineup']['outside_2']['player']->id)->toBe($other->id);
    $component->call('saveManualVariant')->assertHasErrors('manual');
});

test('additional bench players fill vacancies and persist without changing substitutions', function () {
    $component = manualStarterComponent();
    $replacement = Player::factory()->forPosition(PlayerPosition::Opposite)->withVmPlayerId(701)->create(['training_bar' => 80]);
    $component->call('startManualEdit')->assertSee('manualAssignments.bench-1', false);
    $original = $component->get('manualPreview');
    $component->set('manualAssignments.bench-1', (string) $replacement->id);
    $preview = $component->get('manualPreview');
    expect($preview['is_sendable'])->toBeTrue()
        ->and($preview['bench_vm_player_ids'])->toContain(701)
        ->and($preview['plan'])->toBe($original['plan']);
    $component->call('saveManualVariant')->assertHasNoErrors();
    $reloaded = Livewire::test('pages::optimizer.result');
    expect($reloaded->get('activeVariant')['bench_vm_player_ids'])->toContain(701);
    $reloaded->call('startManualEdit')->set('manualAssignments.bench-1', '')->call('saveManualVariant')->assertHasNoErrors();
    expect($reloaded->get('activeVariant')['bench_vm_player_ids'])->not->toContain(701);
});

test('additional bench options exclude starters and unavailable players', function () {
    $component = manualStarterComponent();
    $healthy = Player::factory()->forPosition(PlayerPosition::Opposite)->withVmPlayerId(702)->create(['training_bar' => 90]);
    $injured = Player::factory()->forPosition(PlayerPosition::Opposite)->create(['is_injured' => true]);
    $component->call('startManualEdit');
    $starterIds = collect($component->get('manualPreview')['lineup'])->pluck('player.id')->all();
    $options = collect($component->instance()->manualBenchPlayerOptions(1))->pluck('id');
    expect($options)->toContain($healthy->id)->not->toContain($injured->id);
    foreach ($starterIds as $starterId) {
        expect($options)->not->toContain($starterId);
    }
});

test('forged additional bench assignments cannot be saved', function (string $kind) {
    $component = manualStarterComponent();
    $reserve = Player::factory()->forPosition(PlayerPosition::Opposite)->withVmPlayerId(703)->create(['training_bar' => 90]);
    $injured = Player::factory()->forPosition(PlayerPosition::Opposite)->create(['is_injured' => true]);
    $component->call('startManualEdit');
    $value = match ($kind) {
        'starter' => $component->get('manualPreview')['lineup']['libero']['player']->id,
        'injured' => $injured->id,
        'missing' => 999999,
        'duplicate' => $reserve->id,
        default => 'garbage',
    };
    if ($kind === 'duplicate') {
        $component->set('manualAssignments.bench-2', (string) $reserve->id);
    }
    $component->set('manualAssignments.bench-1', (string) $value);
    expect($component->get('manualPreview')['is_sendable'])->toBeFalse();
    $component->call('saveManualVariant')->assertHasErrors('manual');
})->with(['starter', 'injured', 'missing', 'duplicate', 'malformed']);

test('filling a vacancy automatically calculates changes for the selected reserve', function () {
    $component = manualStarterComponent();
    $full = Player::factory()->forPosition(PlayerPosition::Setter)->withVmPlayerId(801)->create(['training_bar' => 100]);
    $component->call('startManualEdit');
    $originalStarter = $component->get('manualPreview')['lineup']['setter']['player'];
    $component->set('manualAssignments.starter-setter', (string) $full->id);
    $withoutReserve = $component->get('manualPreview');
    expect($withoutReserve['total_gained_training'])->toBe(0);

    $component->set('manualAssignments.bench-1', (string) $originalStarter->id);
    $withReserve = $component->get('manualPreview');
    expect($withReserve['is_sendable'])->toBeTrue()
        ->and($withReserve['total_gained_training'])->toBe(50)
        ->and($withReserve['lineup']['setter']['player']->id)->toBe($full->id)
        ->and(collect($withReserve['plan']['slots'][0]['sets'])->pluck('active_player.id'))->toContain($originalStarter->id)
        ->and($withReserve['bench_vm_player_ids'])->toBe([$originalStarter->vm_player_id]);
    foreach (app(VmSubstitutionService::class)->buildPayloads($withReserve['plan']) as $payload) {
        expect($payload['playerOut'])->toBe(801)->and($payload['playerIn'])->toBe($originalStarter->vm_player_id);
    }
    $component->call('saveManualVariant')->assertHasNoErrors();
    $reloaded = Livewire::test('pages::optimizer.result');
    expect($reloaded->get('activeVariant')['plan'])->toBe($withReserve['plan']);
    $reloaded->call('startManualEdit')->set('manualAssignments.bench-1', '');
    $cleared = $reloaded->get('manualPreview');
    expect($cleared['is_sendable'])->toBeTrue()
        ->and($cleared['total_gained_training'])->toBe(0)
        ->and($cleared['bench_vm_player_ids'])->toBe([]);
});

test('promoting a reserve frees its bench slot and the former starter can be optimized from that slot', function () {
    $component = manualStarterComponent();
    Player::factory()->forPosition(PlayerPosition::Setter)->withVmPlayerId(802)->create(['training_bar' => 20]);
    $component->call('startManualEdit');
    $original = $component->get('manualPreview');
    $formerStarter = $original['lineup']['setter']['player'];
    $promoted = collect($original['bench'])->filter()->first();
    expect($promoted)->not->toBeNull();
    $component->set('manualAssignments.starter-setter', (string) $promoted->id);
    $afterPromotion = $component->get('manualPreview');
    expect($afterPromotion['is_sendable'])->toBeTrue()
        ->and($afterPromotion['lineup']['setter']['player']->id)->toBe($promoted->id)
        ->and($afterPromotion['bench_vm_player_ids'])->not->toContain($promoted->vm_player_id);
    $component->set('manualAssignments.bench-1', (string) $formerStarter->id);
    $filled = $component->get('manualPreview');
    expect($filled['is_sendable'])->toBeTrue()
        ->and($filled['bench_vm_player_ids'])->toContain($formerStarter->vm_player_id)
        ->and($filled['total_gained_training'])->toBeGreaterThan($afterPromotion['total_gained_training']);
});

test('infeasible automatic changes block saving until the required reserve is selected', function () {
    $component = manualStarterComponent();
    $originalStarter = $component->get('activeVariant')['lineup']['setter']['player'];
    Player::factory()->forPosition(PlayerPosition::Setter)->withVmPlayerId(803)->create(['training_bar' => 100]);
    $input = session('optimizer.input');
    $input['player_constraints'] = [['kind' => 'set', 'player_id' => $originalStarter->id, 'position' => PlayerPosition::Setter->value, 'set_number' => 1]];
    session()->put('optimizer.input', $input);
    $component = Livewire::test('pages::optimizer.result')->call('startManualEdit');
    $full = Player::query()->where('vm_player_id', 803)->firstOrFail();
    $component->set('manualAssignments.starter-setter', (string) $full->id);
    expect($component->get('manualPreview')['is_sendable'])->toBeFalse();
    $component->call('saveManualVariant')->assertHasErrors('manual');
    $component->set('manualAssignments.bench-1', (string) $originalStarter->id);
    expect($component->get('manualPreview')['is_sendable'])->toBeTrue();
    $component->call('saveManualVariant')->assertHasNoErrors();
});

test('matrix reset restores the optimizer lineup bench and set assignments during editing', function () {
    $component = manualStarterComponent();
    $replacement = Player::factory()->forPosition(PlayerPosition::Setter)->withVmPlayerId(804)->create(['training_bar' => 100]);
    $component->call('startManualEdit');
    $original = $component->get('manualPreview');
    $component->set('manualAssignments.starter-setter', (string) $replacement->id)
        ->assertSee('Plan zmian · tryb edycji')
        ->call('resetManualPlan')->assertHasNoErrors();
    $reset = $component->get('manualPreview');
    expect($reset['lineup']['setter']['player']->id)->toBe($original['lineup']['setter']['player']->id)
        ->and($reset['plan'])->toBe($original['plan'])
        ->and($reset['bench_vm_player_ids'])->toBe($original['bench_vm_player_ids']);
});
