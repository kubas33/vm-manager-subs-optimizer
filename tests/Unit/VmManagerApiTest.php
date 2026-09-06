<?php

use App\Packages\VmManagerApi\Services\VmManagerApiService;
use App\Packages\VmManagerApi\VmManagerApi;
use App\TrainingBarImportService;
use App\VmTacticsService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
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

test('tactics authentication failure while loading existing changes stops the write', function () {
    session()->put('vm_auth.token', Crypt::encryptString('session-token'));

    Http::fake(function (Request $request) {
        if ($request->method() === 'GET') {
            return Http::response([
                'message' => 'response-secret-value',
            ], 401);
        }

        return Http::response(['ok' => true]);
    });

    $exception = null;

    try {
        app(VmTacticsService::class)->pushRecommendation([]);
    } catch (RuntimeException $caught) {
        $exception = $caught;
    }

    expect($exception)->toBeInstanceOf(RuntimeException::class)
        ->and($exception?->getMessage())->toBe('VM Manager authentication expired. Please log in again.')
        ->and($exception?->getMessage())->not->toContain('response-secret-value')
        ->and(Http::recorded())->toHaveCount(1)
        ->and(app(VmManagerApiService::class)->isAuthenticated())->toBeFalse();
});
