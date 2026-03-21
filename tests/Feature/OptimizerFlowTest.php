<?php

use App\Enums\PlayerPosition;
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
        ->set('scenarioMode', 'preset')
        ->set('presetKey', 'standard_3_0')
        ->call('submit')
        ->assertHasNoErrors()
        ->assertRedirect(route('optimizer.result'));

    expect(session('optimizer.input'))->toMatchArray([
        'scenario_mode' => 'preset',
        'scenario_source' => 'standard_3_0',
    ]);

    $this->get(route('optimizer.result'))
        ->assertOk()
        ->assertSee('Rozgrywający')
        ->assertSee('Środkowy')
        ->assertSee('Preset')
        ->assertSee('Standardowe 3:0')
        ->assertSee('25:20, 25:18, 25:22')
        ->assertSee('Top warianty')
        ->assertSee('Setter Alpha')
        ->assertSee('Middle Alpha');
});

test('optimizer form allows the same position in both analyzed slots', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test('pages::optimizer.create')
        ->set('primaryPosition', PlayerPosition::MiddleBlocker->value)
        ->set('secondaryPosition', PlayerPosition::MiddleBlocker->value)
        ->call('submit')
        ->assertHasNoErrors()
        ->assertRedirect(route('optimizer.result'));

    expect(collect(session('optimizer.input.positions'))->pluck('value')->all())
        ->toBe([
            PlayerPosition::MiddleBlocker->value,
            PlayerPosition::MiddleBlocker->value,
        ]);
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
