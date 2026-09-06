<?php

use App\Enums\PlayerPosition;
use App\MatchScenario;
use App\Models\Player;
use App\Models\User;
use App\Packages\VmManagerApi\Services\VmManagerApiService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
    config()->set('vm-manager.api_url', 'https://faster.vm-manager.org');
    config()->set('vm-manager.api_token', null);
    Http::preventStrayRequests();
});

function seedVmSubstitutionScenario(): void
{
    Player::factory()->forPosition(PlayerPosition::Setter)->withVmPlayerId(90101)->create(['training_bar' => 0]);
    Player::factory()->forPosition(PlayerPosition::Setter)->withVmPlayerId(90202)->create(['training_bar' => 30]);

    session()->put('optimizer.input', [
        'positions' => [['value' => PlayerPosition::Setter->value, 'label' => 'Rozgrywający', 'active_players' => 2]],
        'scenario_mode' => 'preset',
        'scenario_mode_label' => 'Preset',
        'scenario_source' => 'standard_3_0',
        'scenario_source_label' => 'Standardowe 3:0',
        'fairness_threshold' => 20,
        'scenario_safety_mode' => false,
        'reserve_pools' => [[
            'position' => PlayerPosition::Setter->value,
            'position_label' => 'Rozgrywający',
            'slot_count' => 1,
            'reserve_limit' => 1,
            'candidate_limit' => 2,
        ]],
        'scenarios' => [MatchScenario::fromInput('25:20, 25:18, 25:22', 'Test 3:0')->toArray()],
    ]);
}

test('dashboard VM login keeps secrets out of Livewire snapshots and disconnect clears the session', function () {
    Http::fake(['*/api/auth/login' => Http::response(['success' => true, 'token' => 'private-vm-token'])]);

    $component = Livewire::test('pages::dashboard')
        ->assertSee('Połączenie z VM Manager')
        ->set('vmLogin', 'example-login')
        ->set('vmPassword', 'private-password')
        ->call('loginToVm')
        ->assertHasNoErrors()
        ->assertSet('vmPassword', '')
        ->assertSee('Połączono z VM Manager')
        ->assertDontSee('private-vm-token')
        ->assertDontSee('private-password');

    expect(app(VmManagerApiService::class)->isAuthenticated())->toBeTrue();
    expect(json_encode($component->snapshot))->not->toContain('private-vm-token', 'private-password');
    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request->url() === 'https://faster.vm-manager.org/api/auth/login'
        && $request['login'] === 'example-login'
        && $request['password'] === 'private-password');

    $component->call('logoutFromVm')->assertSee('Rozłączono z VM Manager.');
    expect(app(VmManagerApiService::class)->isAuthenticated())->toBeFalse();
});

test('failed VM login clears password and does not display raw response', function () {
    Http::fake(['*' => Http::response(['success' => false, 'message' => 'private-server-data'], 401)]);

    Livewire::test('pages::dashboard')
        ->set('vmLogin', 'example-login')
        ->set('vmPassword', 'private-password')
        ->call('loginToVm')
        ->assertHasErrors('vmConnection')
        ->assertSet('vmPassword', '')
        ->assertDontSee('private-server-data');
    expect(app(VmManagerApiService::class)->isAuthenticated())->toBeFalse();
});

test('password is also cleared when login validation fails', function () {
    Livewire::test('pages::dashboard')
        ->set('vmPassword', 'private-password')
        ->call('loginToVm')
        ->assertHasErrors('vmLogin')
        ->assertSet('vmPassword', '');
    Http::assertNothingSent();
});

test('chosen optimizer variant sends VM substitutions only after its action using login token', function () {
    seedVmSubstitutionScenario();
    session()->put('vm_auth.token', Crypt::encryptString('login-token'));

    Http::fake([
        '*/api/tactics/changes*' => Http::response([]),
    ]);

    Livewire::test('pages::optimizer.result')
        ->assertSee('Wyślij zmiany do gry')
        ->assertDontSee('Połączenie z VM Manager')
        ->set('tacticsMatchType', 'Friendly')
        ->call('pushSubstitutions', 0)
        ->assertHasNoErrors()
        ->assertSee('Zapisano zmian:');

    Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
        && str_contains($request->url(), '/api/tactics/changes?type=Friendly')
        && $request->hasHeader('Authorization', 'Bearer login-token'));
    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && str_ends_with($request->url(), '/api/tactics/changes')
        && $request->hasHeader('Authorization', 'Bearer login-token')
        && $request['matchType'] === 'Friendly'
        && in_array($request['playerIn'], [90101, 90202], true)
        && in_array($request['playerOut'], [90101, 90202], true)
        && $request['playerIn'] !== $request['playerOut']);
});

test('invalid variant or match type cannot send changes', function () {
    seedVmSubstitutionScenario();
    Livewire::test('pages::optimizer.result')
        ->call('pushSubstitutions', 999)
        ->assertHasErrors('substitutions')
        ->set('tacticsMatchType', 'unexpected')
        ->call('pushSubstitutions', 0)
        ->assertHasErrors('tacticsMatchType');
    Http::assertNothingSent();
});

test('missing VM IDs prevent any external requests from the optimizer action', function () {
    seedVmSubstitutionScenario();
    Player::query()->update(['vm_player_id' => null]);
    Livewire::test('pages::optimizer.result')
        ->call('pushSubstitutions', 0)
        ->assertHasErrors('substitutions');
    Http::assertNothingSent();
});

test('VM actions reject unauthenticated callers when application auth is enabled', function () {
    config()->set('auth.disable_auth', false);
    auth()->logout();
    Livewire::test('pages::dashboard')
        ->call('loginToVm')
        ->assertForbidden();
    Livewire::test('pages::optimizer.result')
        ->call('pushSubstitutions', 0)
        ->assertForbidden();
    Http::assertNothingSent();
});

test('repeated invalid VM logins are rate limited', function () {
    Http::fake(['*' => Http::response(['success' => false], 401)]);
    $component = Livewire::test('pages::dashboard')->set('vmLogin', 'invalid-login');

    for ($attempt = 0; $attempt < 6; $attempt++) {
        $component->set('vmPassword', 'invalid-password')->call('loginToVm');
    }

    $component->assertHasErrors('vmConnection')->assertSet('vmPassword', '')
        ->assertSee('Zbyt wiele prób logowania.');
    Http::assertSentCount(5);
});
