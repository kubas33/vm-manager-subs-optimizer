<?php

use App\Enums\PlayerPosition;
use App\MatchScenario;
use App\Models\Player;
use App\Models\User;
use App\Packages\VmManagerApi\Services\VmManagerApiService;
use App\VmSubstitutionService;
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
    Player::factory()->forPosition(PlayerPosition::Opposite)->withVmPlayerId(90303)->create(['training_bar' => 0]);
    Player::factory()->forPosition(PlayerPosition::MiddleBlocker)->withVmPlayerId(90404)->create(['training_bar' => 0]);
    Player::factory()->forPosition(PlayerPosition::MiddleBlocker)->withVmPlayerId(90505)->create(['training_bar' => 10]);
    Player::factory()->forPosition(PlayerPosition::OutsideHitter)->withVmPlayerId(90606)->create(['training_bar' => 0]);
    Player::factory()->forPosition(PlayerPosition::OutsideHitter)->withVmPlayerId(90707)->create(['training_bar' => 10]);
    Player::factory()->forPosition(PlayerPosition::Libero)->withVmPlayerId(90808)->create(['training_bar' => 0]);

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

    Http::fake(function (Request $request) {
        if (str_contains($request->url(), '/api/tactics/changes')) {
            return Http::response([]);
        }

        return Http::response(['ok' => true]);
    });

    Livewire::test('pages::optimizer.result')
        ->assertSee('Wyślij skład i zmiany do gry')
        ->assertDontSee('Połączenie z VM Manager')
        ->set('tacticsMatchType', 'Friendly')
        ->call('pushSubstitutions', 0)
        ->assertHasNoErrors()
        ->assertSee('Zapisano zmian:');

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && str_ends_with($request->url(), '/api/tactics')
        && $request->hasHeader('Authorization', 'Bearer login-token')
        && $request['matchType'] === 'Friendly'
        && $request['player1'] === 90101
        && $request['player4'] === 90303
        && $request['player7'] === 90808);
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

test('chosen optimizer variant sends its reserve in the full VM tactics payload before its changes', function () {
    seedVmSubstitutionScenario();
    session()->put('vm_auth.token', Crypt::encryptString('login-token'));

    $component = Livewire::test('pages::optimizer.result');
    $selectedPlan = $component->get('rankedPlans')[0]['plan'];
    $changePayloads = app(VmSubstitutionService::class)->buildPayloads($selectedPlan);
    $benchPlayerIds = collect($changePayloads)->pluck('playerIn')->unique()->values()->all();
    Http::fake(function (Request $request) {
        if (str_contains($request->url(), '/api/tactics/changes')) {
            return Http::response([]);
        }

        return Http::response(['ok' => true]);
    });

    $component
        ->call('pushSubstitutions', 0)
        ->assertHasNoErrors()
        ->assertSee('Zapisano zmian:');

    Http::assertSentInOrder([
        function (Request $request) use ($benchPlayerIds): bool {
            $expectedBenchPlayerIds = array_pad($benchPlayerIds, 5, null);

            return $request->method() === 'POST'
                && str_ends_with($request->url(), '/api/tactics')
                && $request['matchType'] === 'League'
                && $request['player1'] === 90101
                && $request['player2'] === 90606
                && $request['player3'] === 90404
                && $request['player4'] === 90303
                && $request['player5'] === 90707
                && $request['player6'] === 90505
                && $request['player7'] === 90808
                && $request['player8'] === $expectedBenchPlayerIds[0]
                && $request['player9'] === $expectedBenchPlayerIds[1]
                && $request['player10'] === $expectedBenchPlayerIds[2]
                && $request['player11'] === $expectedBenchPlayerIds[3]
                && $request['player12'] === $expectedBenchPlayerIds[4];
        },
        fn (Request $request): bool => $request->method() === 'GET'
            && str_contains($request->url(), '/api/tactics/changes?type=League'),
        fn (Request $request): bool => $request->method() === 'POST'
            && str_ends_with($request->url(), '/api/tactics/changes'),
    ]);
});

test('existing broader substitution is updated with the exact plan sets', function () {
    session()->put('vm_auth.token', Crypt::encryptString('login-token'));

    Http::fake(function (Request $request) {
        if ($request->method() === 'GET') {
            return Http::response([[
                'changeId' => 5978797,
                'matchType' => 'League',
                'playerIn' => 2032798,
                'playerOut' => 2004528,
                'set1' => 1,
                'set2' => 1,
                'set3' => 1,
                'set4' => 1,
                'set5' => 1,
                'matchStatePoints' => 1,
            ]]);
        }

        return Http::response(['changeId' => 5978797]);
    });

    $payload = [
        'changeId' => null,
        'matchType' => 'League',
        'playerIn' => 2032798,
        'playerOut' => 2004528,
        'set1' => 0,
        'set2' => 0,
        'set3' => 0,
        'set4' => 1,
        'set5' => 0,
        'matchStatePoints' => 1,
    ];

    $result = app(VmSubstitutionService::class)->pushPreparedPayloads([$payload]);

    expect($result)->toMatchArray(['created' => 1, 'skipped' => 0, 'error' => null]);
    Http::assertSent(function (Request $request): bool {
        return $request->method() === 'POST'
            && str_ends_with($request->url(), '/api/tactics/changes')
            && $request['changeId'] === 5978797
            && $request['set1'] === 0
            && $request['set2'] === 0
            && $request['set3'] === 0
            && $request['set4'] === 1
            && $request['set5'] === 0;
    });
});

test('optimizer can delete all current VM substitution changes for the selected match type', function () {
    session()->put('vm_auth.token', Crypt::encryptString('login-token'));

    Http::fake(function (Request $request) {
        if ($request->method() === 'GET') {
            return Http::response([
                ['changeId' => 5847245, 'matchType' => 'League'],
                ['changeId' => 5978797, 'matchType' => 'League'],
            ]);
        }

        return Http::response([], 204);
    });

    Livewire::test('pages::optimizer.result')
        ->assertSee('Usuń wszystkie zmiany')
        ->call('requestDeleteAllChanges')
        ->assertSet('confirmingChangesDeletion', true)
        ->call('deleteAllChanges')
        ->assertHasNoErrors()
        ->assertSet('confirmingChangesDeletion', false)
        ->assertSee('Usunięto zmian: 2.');

    Http::assertSentInOrder([
        fn (Request $request): bool => $request->method() === 'GET'
            && $request->url() === 'https://faster.vm-manager.org/api/tactics/changes?type=League'
            && $request->hasHeader('Authorization', 'Bearer login-token'),
        fn (Request $request): bool => $request->method() === 'DELETE'
            && $request->url() === 'https://faster.vm-manager.org/api/tactics/changes/5847245?type=League'
            && $request->hasHeader('Authorization', 'Bearer login-token'),
        fn (Request $request): bool => $request->method() === 'DELETE'
            && $request->url() === 'https://faster.vm-manager.org/api/tactics/changes/5978797?type=League'
            && $request->hasHeader('Authorization', 'Bearer login-token'),
    ]);
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
