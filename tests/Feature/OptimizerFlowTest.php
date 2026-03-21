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
        ->assertRedirect(route('optimizer.result'));

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
        ->assertSee('Brak danych wejściowych');
});
