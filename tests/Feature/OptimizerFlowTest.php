<?php

use App\Enums\PlayerPosition;
use App\MatchScenario;
use App\Models\Player;
use App\Models\User;
use Livewire\Livewire;

test('optimizer form stores normalized preset input and redirects to result page', function () {
    $this->actingAs(User::factory()->create());

    Player::factory()->create([
        'name' => 'Setter Alpha',
        'position' => PlayerPosition::Setter,
        'training_bar' => 0,
    ]);
    Player::factory()->create([
        'name' => 'Setter Beta',
        'position' => PlayerPosition::Setter,
        'training_bar' => 0,
    ]);
    Player::factory()->create([
        'name' => 'Middle Alpha',
        'position' => PlayerPosition::MiddleBlocker,
        'training_bar' => 0,
    ]);
    Player::factory()->create([
        'name' => 'Middle Beta',
        'position' => PlayerPosition::MiddleBlocker,
        'training_bar' => 0,
    ]);

    Livewire::test('pages::optimizer.create')
        ->set('primaryPosition', PlayerPosition::Setter->value)
        ->set('secondaryPosition', PlayerPosition::MiddleBlocker->value)
        ->set('reserveLimitsByPosition.'.PlayerPosition::Setter->value, '2')
        ->set('reserveLimitsByPosition.'.PlayerPosition::MiddleBlocker->value, '3')
        ->set('fairnessThreshold', '20')
        ->set('scenarioMode', 'preset')
        ->set('presetKey', 'standard_3_0')
        ->call('submit')
        ->assertHasNoErrors()
        ->assertRedirect(route('optimizer.result'))
        ->assertDispatched('optimizer-draft-saved');

    expect(session('optimizer.input'))->toMatchArray([
        'scenario_mode' => 'preset',
        'scenario_source' => 'standard_3_0',
        'fairness_threshold' => 20,
        'reserve_pools' => [
            [
                'position' => PlayerPosition::Setter->value,
                'position_label' => PlayerPosition::Setter->label(),
                'slot_count' => 1,
                'reserve_limit' => 2,
                'candidate_limit' => 3,
            ],
            [
                'position' => PlayerPosition::MiddleBlocker->value,
                'position_label' => PlayerPosition::MiddleBlocker->label(),
                'slot_count' => 1,
                'reserve_limit' => 3,
                'candidate_limit' => 4,
            ],
        ],
    ]);

    $this->get(route('optimizer.result'))
        ->assertOk()
        ->assertSee('Propozycja składu')
        ->assertSee('Rozgrywający')
        ->assertSee('Środkowy')
        ->assertSee('Preset')
        ->assertSee('Standardowe 3:0')
        ->assertSee('25:20, 25:18, 25:22')
        ->assertSee('Pule rezerwowych')
        ->assertSee('Próg minimalnego paska: 20%')
        ->assertSee('Top warianty')
        ->assertSee('Setter Alpha')
        ->assertSee('Middle Alpha');
});

test('optimizer form allows the same position in both analyzed slots', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test('pages::optimizer.create')
        ->set('primaryPosition', PlayerPosition::MiddleBlocker->value)
        ->set('secondaryPosition', PlayerPosition::MiddleBlocker->value)
        ->set('sharedReserveLimit', '5')
        ->call('submit')
        ->assertHasNoErrors()
        ->assertRedirect(route('optimizer.result'));

    expect(collect(session('optimizer.input.positions'))->pluck('value')->all())
        ->toBe([
            PlayerPosition::MiddleBlocker->value,
            PlayerPosition::MiddleBlocker->value,
        ]);

    expect(session('optimizer.input.reserve_pools'))->toBe([
        [
            'position' => PlayerPosition::MiddleBlocker->value,
            'position_label' => PlayerPosition::MiddleBlocker->label(),
            'slot_count' => 2,
            'reserve_limit' => 5,
            'candidate_limit' => 7,
        ],
    ]);
});

test('optimizer form shows separate reserve pool inputs for different positions', function () {
    $this->actingAs(User::factory()->create());

    $this->get(route('optimizer.create'))
        ->assertOk()
        ->assertSee('Pula rezerwowych dla Rozgrywający')
        ->assertSee('Pula rezerwowych dla Środkowy')
        ->assertDontSee('Wspólna pula rezerwowych');
});

test('optimizer form shows shared reserve pool input for identical positions', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test('pages::optimizer.create')
        ->set('primaryPosition', PlayerPosition::MiddleBlocker->value)
        ->set('secondaryPosition', PlayerPosition::MiddleBlocker->value)
        ->assertSee('Wspólna pula rezerwowych')
        ->assertDontSee('Pula rezerwowych dla Środkowy');
});

test('optimizer form restores a saved draft for shared reserve pool', function () {
    $this->actingAs(User::factory()->create());

    $component = Livewire::test('pages::optimizer.create')
        ->call('restoreDraft', [
            'primaryPosition' => PlayerPosition::MiddleBlocker->value,
            'secondaryPosition' => PlayerPosition::MiddleBlocker->value,
            'scenarioMode' => 'preset',
            'presetKey' => 'standard_3_1',
            'singleScenario' => '25:20, 25:18, 25:22',
            'multipleScenarios' => "25:20, 25:18, 25:22\n25:22, 22:25, 25:21, 25:19",
            'sharedReserveLimit' => '4',
            'fairnessThreshold' => '30',
            'scenarioSafetyMode' => true,
            'reserveLimitsByPosition' => [
                PlayerPosition::Setter->value => '2',
            ],
        ]);

    $component
        ->assertSet('primaryPosition', PlayerPosition::MiddleBlocker->value)
        ->assertSet('secondaryPosition', PlayerPosition::MiddleBlocker->value)
        ->assertSet('scenarioMode', 'preset')
        ->assertSet('presetKey', 'standard_3_1')
        ->assertSet('sharedReserveLimit', '4')
        ->assertSet('fairnessThreshold', '30')
        ->assertSet('scenarioSafetyMode', true);

    expect($component->instance()->usesSharedReservePool())->toBeTrue();
});

test('optimizer form restores a saved draft for separate reserve pools', function () {
    $this->actingAs(User::factory()->create());

    $component = Livewire::test('pages::optimizer.create')
        ->call('restoreDraft', [
            'primaryPosition' => PlayerPosition::Setter->value,
            'secondaryPosition' => PlayerPosition::OutsideHitter->value,
            'scenarioMode' => 'multiple',
            'presetKey' => 'hard_3_2',
            'singleScenario' => '25:20, 25:18, 25:22',
            'multipleScenarios' => "25:20, 25:18, 25:22\n25:22, 22:25, 25:21, 25:19",
            'sharedReserveLimit' => '3',
            'fairnessThreshold' => '18',
            'scenarioSafetyMode' => false,
            'reserveLimitsByPosition' => [
                PlayerPosition::Setter->value => '2',
                PlayerPosition::OutsideHitter->value => '3',
            ],
        ]);

    $component
        ->assertSet('primaryPosition', PlayerPosition::Setter->value)
        ->assertSet('secondaryPosition', PlayerPosition::OutsideHitter->value)
        ->assertSet('scenarioMode', 'multiple')
        ->assertSet('presetKey', 'hard_3_2')
        ->assertSet('sharedReserveLimit', '3')
        ->assertSet('fairnessThreshold', '18')
        ->assertSet('scenarioSafetyMode', false);

    expect($component->instance()->usesSharedReservePool())->toBeFalse()
        ->and($component->instance()->reserveLimitsByPosition)->toMatchArray([
            PlayerPosition::Setter->value => '2',
            PlayerPosition::OutsideHitter->value => '3',
        ]);
});

test('optimizer form ignores invalid draft values and keeps defaults', function () {
    $this->actingAs(User::factory()->create());

    $component = Livewire::test('pages::optimizer.create')
        ->call('restoreDraft', [
            'primaryPosition' => 'not-a-position',
            'secondaryPosition' => [],
            'scenarioMode' => 'invalid',
            'presetKey' => 'missing',
            'singleScenario' => '',
            'multipleScenarios' => '',
            'sharedReserveLimit' => '999',
            'fairnessThreshold' => '-1',
            'scenarioSafetyMode' => 'maybe',
            'reserveLimitsByPosition' => [
                PlayerPosition::Setter->value => '9',
            ],
        ]);

    $component
        ->assertSet('primaryPosition', PlayerPosition::Setter->value)
        ->assertSet('secondaryPosition', PlayerPosition::MiddleBlocker->value)
        ->assertSet('scenarioMode', 'preset')
        ->assertSet('presetKey', 'standard_3_0')
        ->assertSet('sharedReserveLimit', '5')
        ->assertSet('fairnessThreshold', '20')
        ->assertSet('scenarioSafetyMode', false);

    expect($component->instance()->reserveLimitsByPosition)->toMatchArray([
        PlayerPosition::Setter->value => '2',
        PlayerPosition::MiddleBlocker->value => '2',
    ]);
});

test('optimizer form renders browser draft storage hook with versioned key', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $this->get(route('optimizer.create'))
        ->assertOk()
        ->assertSee("optimizer.create.last-config.v1.user-{$user->id}", false)
        ->assertSee('optimizer-draft-saved', false);
});

test('optimizer form defaults safe mode for longer presets', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test('pages::optimizer.create')
        ->assertSet('scenarioSafetyMode', false)
        ->set('presetKey', 'standard_3_1')
        ->assertSet('scenarioSafetyMode', true)
        ->set('presetKey', 'standard_3_0')
        ->assertSet('scenarioSafetyMode', false)
        ->set('presetKey', 'hard_3_2')
        ->assertSet('scenarioSafetyMode', true);
});

test('optimizer result page maps shared reserve pool to both analyzed slots', function () {
    $this->actingAs(User::factory()->create());

    session()->put('optimizer.input', [
        'positions' => [
            [
                'value' => PlayerPosition::MiddleBlocker->value,
                'label' => PlayerPosition::MiddleBlocker->label(),
                'active_players' => 9,
            ],
            [
                'value' => PlayerPosition::MiddleBlocker->value,
                'label' => PlayerPosition::MiddleBlocker->label(),
                'active_players' => 9,
            ],
        ],
        'scenario_mode' => 'preset',
        'scenario_mode_label' => 'Preset',
        'scenario_source' => 'standard_3_0',
        'scenario_source_label' => 'Standardowe 3:0',
        'fairness_threshold' => 20,
        'reserve_pools' => [
            [
                'position' => PlayerPosition::MiddleBlocker->value,
                'position_label' => PlayerPosition::MiddleBlocker->label(),
                'slot_count' => 2,
                'reserve_limit' => 5,
                'candidate_limit' => 7,
            ],
        ],
        'scenarios' => [
            MatchScenario::fromInput('25:20, 25:18, 25:22', 'Standardowe 3:0')->toArray(),
        ],
    ]);

    $slotDefinitions = Livewire::test('pages::optimizer.result')
        ->instance()
        ->slotDefinitions();

    expect($slotDefinitions)
        ->toHaveCount(2)
        ->and($slotDefinitions[0]['reserve_limit'])->toBe(5)
        ->and($slotDefinitions[1]['reserve_limit'])->toBe(5);
});

test('optimizer result page shows multiple variants for a large shared middle blocker pool', function () {
    $this->actingAs(User::factory()->create());

    Player::factory()->create([
        'name' => 'Middle A',
        'position' => PlayerPosition::MiddleBlocker,
        'training_bar' => 0,
    ]);
    Player::factory()->create([
        'name' => 'Middle B',
        'position' => PlayerPosition::MiddleBlocker,
        'training_bar' => 3,
    ]);
    Player::factory()->create([
        'name' => 'Middle C',
        'position' => PlayerPosition::MiddleBlocker,
        'training_bar' => 14,
    ]);
    Player::factory()->create([
        'name' => 'Middle D',
        'position' => PlayerPosition::MiddleBlocker,
        'training_bar' => 38,
    ]);
    Player::factory()->create([
        'name' => 'Middle E',
        'position' => PlayerPosition::MiddleBlocker,
        'training_bar' => 46,
    ]);
    Player::factory()->create([
        'name' => 'Middle F',
        'position' => PlayerPosition::MiddleBlocker,
        'training_bar' => 52,
    ]);
    Player::factory()->create([
        'name' => 'Middle G',
        'position' => PlayerPosition::MiddleBlocker,
        'training_bar' => 53,
    ]);

    session()->put('optimizer.input', [
        'positions' => [
            [
                'value' => PlayerPosition::MiddleBlocker->value,
                'label' => PlayerPosition::MiddleBlocker->label(),
                'active_players' => 7,
            ],
            [
                'value' => PlayerPosition::MiddleBlocker->value,
                'label' => PlayerPosition::MiddleBlocker->label(),
                'active_players' => 7,
            ],
        ],
        'scenario_mode' => 'preset',
        'scenario_mode_label' => 'Preset',
        'scenario_source' => 'standard_3_0',
        'scenario_source_label' => 'Standardowe 3:0',
        'fairness_threshold' => 20,
        'reserve_pools' => [
            [
                'position' => PlayerPosition::MiddleBlocker->value,
                'position_label' => PlayerPosition::MiddleBlocker->label(),
                'slot_count' => 2,
                'reserve_limit' => 5,
                'candidate_limit' => 7,
            ],
        ],
        'scenarios' => [
            MatchScenario::fromInput('25:20, 25:18, 25:22', 'Standardowe 3:0')->toArray(),
        ],
    ]);

    $this->get(route('optimizer.result'))
        ->assertOk()
        ->assertSee('Wariant 2')
        ->assertSee('wariantów');
});

test('optimizer result page shows safe preset breakdown across shorter scenarios', function () {
    $this->actingAs(User::factory()->create());

    Player::factory()->create([
        'name' => 'Middle A',
        'position' => PlayerPosition::MiddleBlocker,
        'training_bar' => 0,
    ]);
    Player::factory()->create([
        'name' => 'Middle B',
        'position' => PlayerPosition::MiddleBlocker,
        'training_bar' => 3,
    ]);
    Player::factory()->create([
        'name' => 'Middle C',
        'position' => PlayerPosition::MiddleBlocker,
        'training_bar' => 14,
    ]);
    Player::factory()->create([
        'name' => 'Middle D',
        'position' => PlayerPosition::MiddleBlocker,
        'training_bar' => 38,
    ]);
    Player::factory()->create([
        'name' => 'Middle E',
        'position' => PlayerPosition::MiddleBlocker,
        'training_bar' => 46,
    ]);
    Player::factory()->create([
        'name' => 'Middle F',
        'position' => PlayerPosition::MiddleBlocker,
        'training_bar' => 52,
    ]);
    Player::factory()->create([
        'name' => 'Middle G',
        'position' => PlayerPosition::MiddleBlocker,
        'training_bar' => 53,
    ]);

    Livewire::test('pages::optimizer.create')
        ->set('primaryPosition', PlayerPosition::MiddleBlocker->value)
        ->set('secondaryPosition', PlayerPosition::MiddleBlocker->value)
        ->set('scenarioMode', 'preset')
        ->set('presetKey', 'standard_3_1')
        ->set('sharedReserveLimit', '5')
        ->call('submit')
        ->assertHasNoErrors()
        ->assertRedirect(route('optimizer.result'));

    expect(session('optimizer.input'))->toMatchArray([
        'scenario_safety_mode' => true,
        'scenario_safety_mode_label' => 'Bezpieczny',
        'scenario_mode' => 'preset',
        'scenario_source' => 'standard_3_1',
        'scenario_source_label' => 'Standardowe 3:1',
    ]);

    expect(collect(session('optimizer.input.scenarios'))->map(fn (array $scenario): array => [
        'label' => $scenario['label'],
        'input' => $scenario['input'],
        'sets_count' => $scenario['sets_count'],
        'total_actions' => $scenario['total_actions'],
    ])->all())->toBe([
        [
            'label' => 'Standardowe 3:0',
            'input' => '25:20, 25:18, 25:22',
            'sets_count' => 3,
            'total_actions' => 135,
        ],
        [
            'label' => 'Standardowe 3:1',
            'input' => '25:21, 22:25, 25:20, 25:19',
            'sets_count' => 4,
            'total_actions' => 182,
        ],
    ]);

    $this->get(route('optimizer.result'))
        ->assertOk()
        ->assertSee('Tryb bezpieczeństwa: włączony')
        ->assertSee('Standardowe 3:0')
        ->assertSee('Standardowe 3:1')
        ->assertSee('Najgorszy');
});

test('optimizer result page aggregates multiple scenarios in ranking output', function () {
    $this->actingAs(User::factory()->create());

    Player::factory()->create([
        'name' => 'Middle Alpha',
        'position' => PlayerPosition::MiddleBlocker,
        'training_bar' => 0,
    ]);
    Player::factory()->create([
        'name' => 'Middle Beta',
        'position' => PlayerPosition::MiddleBlocker,
        'training_bar' => 3,
    ]);
    Player::factory()->create([
        'name' => 'Middle Gamma',
        'position' => PlayerPosition::MiddleBlocker,
        'training_bar' => 14,
    ]);
    Player::factory()->create([
        'name' => 'Middle Delta',
        'position' => PlayerPosition::MiddleBlocker,
        'training_bar' => 38,
    ]);

    session()->put('optimizer.input', [
        'positions' => [
            [
                'value' => PlayerPosition::MiddleBlocker->value,
                'label' => PlayerPosition::MiddleBlocker->label(),
                'active_players' => 4,
            ],
            [
                'value' => PlayerPosition::MiddleBlocker->value,
                'label' => PlayerPosition::MiddleBlocker->label(),
                'active_players' => 4,
            ],
        ],
        'scenario_mode' => 'multiple',
        'scenario_mode_label' => 'Kilka scenariuszy ręcznych',
        'scenario_source' => 'manual',
        'scenario_source_label' => 'Scenariusze ręczne',
        'fairness_threshold' => 20,
        'reserve_pools' => [
            [
                'position' => PlayerPosition::MiddleBlocker->value,
                'position_label' => PlayerPosition::MiddleBlocker->label(),
                'slot_count' => 2,
                'reserve_limit' => 2,
                'candidate_limit' => 4,
            ],
        ],
        'scenarios' => [
            MatchScenario::fromInput('25:20, 25:18, 25:22', 'Scenariusz 1')->toArray(),
            MatchScenario::fromInput('25:21, 22:25, 25:20, 25:19', 'Scenariusz 2')->toArray(),
        ],
    ]);

    $this->get(route('optimizer.result'))
        ->assertOk()
        ->assertSee('Agregacja scenariuszy: 2')
        ->assertSee('Scenariusz referencyjny: Scenariusz 2')
        ->assertSee('Ranking jest agregowany po wszystkich scenariuszach')
        ->assertSee('Scenariusze: 2');
});

test('optimizer form validates reserve pool sum for different positions', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test('pages::optimizer.create')
        ->set('primaryPosition', PlayerPosition::MiddleBlocker->value)
        ->set('secondaryPosition', PlayerPosition::OutsideHitter->value)
        ->set('reserveLimitsByPosition.'.PlayerPosition::MiddleBlocker->value, '3')
        ->set('reserveLimitsByPosition.'.PlayerPosition::OutsideHitter->value, '3')
        ->call('submit')
        ->assertHasErrors(['reserveLimitsByPosition']);
});

test('optimizer form validates manual scenario format', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test('pages::optimizer.create')
        ->set('scenarioMode', 'single')
        ->set('singleScenario', '25:20, abc, 25:18')
        ->call('submit')
        ->assertHasErrors(['singleScenario']);
});

test('optimizer form rejects empty lines in multiple scenarios mode', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test('pages::optimizer.create')
        ->set('scenarioMode', 'multiple')
        ->set('multipleScenarios', "25:20, 25:18, 25:22\n\n25:22, 22:25, 25:21, 25:19")
        ->call('submit')
        ->assertHasErrors(['multipleScenarios']);
});

test('optimizer result page shows empty state when there is no saved input', function () {
    $this->actingAs(User::factory()->create());

    $this->get(route('optimizer.result'))
        ->assertOk()
        ->assertSee('Propozycja składu')
        ->assertSee('Brak danych wejściowych');
});

test('optimizer result page shows full lineup recommendation when roster is complete', function () {
    $this->actingAs(User::factory()->create());

    Player::factory()->forPosition(PlayerPosition::Opposite)->create([
        'name' => 'Lineup Opposite Low',
        'training_bar' => 5,
    ]);
    Player::factory()->forPosition(PlayerPosition::Opposite)->create([
        'name' => 'Lineup Opposite Alt',
        'training_bar' => 35,
    ]);
    Player::factory()->forPosition(PlayerPosition::MiddleBlocker)->create([
        'name' => 'Lineup Middle Low',
        'training_bar' => 8,
    ]);
    Player::factory()->forPosition(PlayerPosition::MiddleBlocker)->create([
        'name' => 'Lineup Middle High',
        'training_bar' => 22,
    ]);
    Player::factory()->forPosition(PlayerPosition::MiddleBlocker)->create([
        'name' => 'Lineup Middle Alt',
        'training_bar' => 40,
    ]);
    Player::factory()->forPosition(PlayerPosition::OutsideHitter)->create([
        'name' => 'Lineup Outside Low',
        'training_bar' => 10,
    ]);
    Player::factory()->forPosition(PlayerPosition::OutsideHitter)->create([
        'name' => 'Lineup Outside Mid',
        'training_bar' => 18,
    ]);
    Player::factory()->forPosition(PlayerPosition::OutsideHitter)->create([
        'name' => 'Lineup Outside Alt',
        'training_bar' => 30,
    ]);
    Player::factory()->forPosition(PlayerPosition::Setter)->create([
        'name' => 'Lineup Setter Low',
        'training_bar' => 12,
    ]);
    Player::factory()->forPosition(PlayerPosition::Setter)->create([
        'name' => 'Lineup Setter Alt',
        'training_bar' => 45,
    ]);
    Player::factory()->forPosition(PlayerPosition::Libero)->create([
        'name' => 'Lineup Libero Low',
        'training_bar' => 7,
    ]);
    Player::factory()->forPosition(PlayerPosition::Libero)->create([
        'name' => 'Lineup Libero Alt',
        'training_bar' => 50,
    ]);

    $this->get(route('optimizer.result'))
        ->assertOk()
        ->assertSee('Propozycja składu')
        ->assertSee('Skład główny')
        ->assertSee('Alternatywy')
        ->assertSee('Lineup Opposite Low')
        ->assertSee('zmiany:')
        ->assertSee('Alternatywa 1')
        ->assertSee('Suma pasków:');
});
