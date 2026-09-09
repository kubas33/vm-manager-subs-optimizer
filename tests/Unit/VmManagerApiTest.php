<?php

use App\Packages\VmManagerApi\Services\VmManagerApiService;
use App\Packages\VmManagerApi\VmManagerApi;
use App\TrainingBarImportService;
use App\VmTacticsService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    config()->set('vm-manager.api_url', 'https://faster.vm-manager.org');
    config()->set('vm-manager.timeout', 20);
    config()->set('vm-manager.api_token', null);
});

test('login posts credentials to the hardcoded API path and stores only an encrypted token', function () {
    $loginUrl = 'https://faster.vm-manager.org/api/auth/login';
    $login = 'Kubas';
    $password = 'correct horse battery staple';
    $token = 'vm-session-token';

    Http::fake([
        $loginUrl => Http::response([
            'success' => true,
            'message' => 'Login successful',
            'token' => $token,
        ]),
    ]);

    app(VmManagerApiService::class)->login($login, $password);

    Http::assertSent(function (Request $request) use ($loginUrl, $login, $password): bool {
        return $request->method() === 'POST'
            && $request->url() === $loginUrl
            && $request->data() === [
                'login' => $login,
                'password' => $password,
            ]
            && $request->header('Content-Type') === ['application/json'];
    });

    $encryptedToken = session()->get('vm_auth.token');

    expect(app(VmManagerApiService::class)->isAuthenticated())->toBeTrue()
        ->and((new VmManagerApi)->resolveToken())->toBe($token)
        ->and($encryptedToken)->toBeString()
        ->and($encryptedToken)->not->toBe($token)
        ->and(Crypt::decryptString($encryptedToken))->toBe($token)
        ->and(serialize(session()->all()))->not->toContain($password);
});

test('VM Manager requests log URL payload and response without secrets', function () {
    config()->set('vm-manager.api_token', 'fallback-token');

    Http::fake([
        'https://faster.vm-manager.org/api/tactics' => Http::response(['ok' => true]),
    ]);

    $logger = Mockery::mock();
    $logger->shouldReceive('debug')
        ->once()
        ->with('VM Manager request', Mockery::on(function (array $context): bool {
            $serialized = json_encode($context);

            return $context['method'] === 'POST'
                && $context['url'] === 'https://faster.vm-manager.org/api/tactics'
                && $context['payload']['matchType'] === 'League'
                && $context['payload']['password'] === '[REDACTED]'
                && $context['payload']['token'] === '[REDACTED]'
                && ! str_contains((string) $serialized, 'fallback-token');
        }));
    $logger->shouldReceive('debug')
        ->once()
        ->with('VM Manager response', Mockery::on(function (array $context): bool {
            return $context['method'] === 'POST'
                && $context['url'] === 'https://faster.vm-manager.org/api/tactics'
                && $context['status'] === 200
                && $context['body'] === ['ok' => true];
        }));
    Log::shouldReceive('channel')
        ->with('vm_manager')
        ->twice()
        ->andReturn($logger);

    app(VmManagerApiService::class)->saveTactics([
        'matchType' => 'League',
        'password' => 'private-password',
        'token' => 'private-token',
    ]);
});

test('VM Manager GET request logs its complete URL and query parameters', function () {
    config()->set('vm-manager.api_token', 'fallback-token');

    Http::fake([
        'https://faster.vm-manager.org/api/tactics*' => Http::response(['idPlayer1' => 101]),
    ]);

    $logger = Mockery::mock();
    $logger->shouldReceive('debug')
        ->once()
        ->with('VM Manager request', Mockery::on(function (array $context): bool {
            return $context['method'] === 'GET'
                && $context['url'] === 'https://faster.vm-manager.org/api/tactics?type=Friendly&matchId=42'
                && $context['query'] === ['type' => 'Friendly', 'matchId' => '42']
                && $context['payload'] === [];
        }));
    $logger->shouldReceive('debug')
        ->once()
        ->with('VM Manager response', Mockery::on(function (array $context): bool {
            return $context['url'] === 'https://faster.vm-manager.org/api/tactics?type=Friendly&matchId=42'
                && $context['status'] === 200;
        }));
    Log::shouldReceive('channel')
        ->with('vm_manager')
        ->twice()
        ->andReturn($logger);

    app(VmManagerApiService::class)->getTactics('Friendly', 42);
});

test('VM Manager DELETE request sends the change ID in the path and match type in the query', function () {
    config()->set('vm-manager.api_token', 'fallback-token');

    Http::fake([
        'https://faster.vm-manager.org/api/tactics/changes/5847245?type=League' => Http::response([], 204),
    ]);

    app(VmManagerApiService::class)->deleteTacticsChange(5847245, 'League');

    Http::assertSent(function (Request $request): bool {
        return $request->method() === 'DELETE'
            && $request->url() === 'https://faster.vm-manager.org/api/tactics/changes/5847245?type=League'
            && $request->data() === []
            && $request->header('Authorization') === ['Bearer fallback-token'];
    });
});

test('VM Manager DELETE request logs its complete URL and query parameters', function () {
    config()->set('vm-manager.api_token', 'fallback-token');

    Http::fake([
        'https://faster.vm-manager.org/api/tactics/changes/5847245?type=League' => Http::response([], 204),
    ]);

    $logger = Mockery::mock();
    $logger->shouldReceive('debug')
        ->once()
        ->with('VM Manager request', Mockery::on(function (array $context): bool {
            return $context['method'] === 'DELETE'
                && $context['url'] === 'https://faster.vm-manager.org/api/tactics/changes/5847245?type=League'
                && $context['query'] === ['type' => 'League']
                && $context['payload'] === [];
        }));
    $logger->shouldReceive('debug')
        ->once()
        ->with('VM Manager response', Mockery::on(function (array $context): bool {
            return $context['method'] === 'DELETE'
                && $context['url'] === 'https://faster.vm-manager.org/api/tactics/changes/5847245?type=League'
                && $context['status'] === 204;
        }));
    Log::shouldReceive('channel')
        ->with('vm_manager')
        ->twice()
        ->andReturn($logger);

    app(VmManagerApiService::class)->deleteTacticsChange(5847245, 'League');
});

test('login rejects invalid responses without exposing response data', function (int $status, array $body) {
    $secret = 'response-secret-value';

    Http::fake([
        'https://faster.vm-manager.org/api/auth/login' => Http::response($body, $status),
    ]);

    $exception = null;

    try {
        app(VmManagerApiService::class)->login('Kubas', 'request-password');
    } catch (RuntimeException $caught) {
        $exception = $caught;
    }

    expect($exception)->toBeInstanceOf(RuntimeException::class)
        ->and($exception?->getMessage())->toBe('VM Manager login failed.')
        ->and($exception?->getMessage())->not->toContain($secret)
        ->and($exception?->getPrevious())->toBeNull()
        ->and(app(VmManagerApiService::class)->isAuthenticated())->toBeFalse();
})->with([
    'http error' => [500, ['message' => 'request failed: response-secret-value']],
    'api failure' => [200, ['success' => false, 'message' => 'response-secret-value']],
    'missing token' => [200, ['success' => true, 'message' => 'response-secret-value']],
    'empty token' => [200, ['success' => true, 'token' => '   ', 'message' => 'response-secret-value']],
]);

test('login requires a configured API URL and nonempty credentials', function () {
    config()->set('vm-manager.api_url', '');

    expect(fn () => app(VmManagerApiService::class)->login('Kubas', 'password'))
        ->toThrow(RuntimeException::class, 'VM Manager API URL is not configured.');

    config()->set('vm-manager.api_url', 'https://faster.vm-manager.org');

    expect(fn () => app(VmManagerApiService::class)->login('', 'password'))
        ->toThrow(RuntimeException::class, 'VM Manager credentials are required.')
        ->and(fn () => app(VmManagerApiService::class)->login('Kubas', ''))
        ->toThrow(RuntimeException::class, 'VM Manager credentials are required.');

    Http::assertNothingSent();
});

test('session token takes precedence over a legacy fallback and logout removes it', function () {
    $sessionToken = 'session-token';
    $fallbackToken = 'legacy-token';

    session()->put('vm_auth.token', Crypt::encryptString($sessionToken));
    config()->set('vm-manager.api_token', $fallbackToken);

    $api = new VmManagerApi;

    expect($api->isAuthenticated())->toBeTrue()
        ->and($api->resolveToken())->toBe($sessionToken);

    $api->logout();

    expect($api->isAuthenticated())->toBeFalse()
        ->and((new VmManagerApi)->resolveToken())->toBe($fallbackToken);
});

test('missing or invalid session token instructs the caller to log in', function () {
    $api = new VmManagerApi;

    expect(fn () => $api->resolveToken())
        ->toThrow(RuntimeException::class, 'VM Manager authentication required. Please log in.');

    session()->put('vm_auth.token', 'not-an-encrypted-token');

    expect((new VmManagerApi)->isAuthenticated())->toBeFalse()
        ->and(fn () => (new VmManagerApi)->resolveToken())
        ->toThrow(RuntimeException::class, 'VM Manager authentication required. Please log in.')
        ->and(session()->has('vm_auth.token'))->toBeFalse();
});

test('unauthorized statuses invalidate the current session token', function (int $status) {
    session()->put('vm_auth.token', Crypt::encryptString('session-token'));

    expect(app(VmManagerApiService::class)->invalidateOnUnauthorized($status))->toBeTrue()
        ->and(app(VmManagerApiService::class)->isAuthenticated())->toBeFalse();
})->with([401, 403]);

test('training bar import uses the authenticated session token before its legacy token', function () {
    config()->set('vm-manager.api_token', 'legacy-token');
    session()->put('vm_auth.token', Crypt::encryptString('session-token'));

    Http::fake([
        'https://faster.vm-manager.org/api/training' => Http::response(['players' => 'invalid']),
    ]);

    expect(fn () => app(TrainingBarImportService::class)->importFromVmManager())
        ->toThrow(RuntimeException::class, 'VM Manager returned an invalid players payload.');

    Http::assertSent(function (Request $request): bool {
        return $request->method() === 'GET'
            && $request->url() === 'https://faster.vm-manager.org/api/training'
            && $request->header('Authorization') === ['Bearer session-token'];
    });
});

test('invalid tactics recommendation stops before any external request', function () {
    session()->put('vm_auth.token', Crypt::encryptString('session-token'));

    Http::fake();

    expect(fn () => app(VmTacticsService::class)->pushRecommendation([]))
        ->toThrow(InvalidArgumentException::class, 'Lineup slot [setter] is empty.');

    expect(Http::recorded())->toHaveCount(0)
        ->and(app(VmManagerApiService::class)->isAuthenticated())->toBeTrue();
});
