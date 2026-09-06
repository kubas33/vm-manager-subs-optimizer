<?php

use App\TrainingBarImportService;
use App\VmAuthService;
use App\VmTacticsService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class);

test('login posts credentials and stores only an encrypted token in the session', function () {
    $loginUrl = 'https://faster.vm-manager.org/api/login';
    $login = 'Kubas';
    $password = 'correct horse battery staple';
    $token = 'vm-session-token';

    config()->set('services.vm_auth.login_url', $loginUrl);

    Http::fake([
        $loginUrl => Http::response([
            'success' => true,
            'message' => 'Login successful',
            'token' => $token,
        ]),
    ]);

    $service = app(VmAuthService::class);
    $service->login($login, $password);

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

    expect($service->isAuthenticated())->toBeTrue()
        ->and($service->token())->toBe($token)
        ->and($encryptedToken)->toBeString()
        ->and($encryptedToken)->not->toBe($token)
        ->and(Crypt::decryptString($encryptedToken))->toBe($token)
        ->and(serialize(session()->all()))->not->toContain($password);
});

test('login rejects invalid responses without exposing response data', function (int $status, array $body) {
    $loginUrl = 'https://faster.vm-manager.org/api/login';
    $secret = 'response-secret-value';

    config()->set('services.vm_auth.login_url', $loginUrl);

    Http::fake([
        $loginUrl => Http::response($body, $status),
    ]);

    $exception = null;

    try {
        app(VmAuthService::class)->login('Kubas', 'request-password');
    } catch (RuntimeException $caught) {
        $exception = $caught;
    }

    expect($exception)->toBeInstanceOf(RuntimeException::class)
        ->and($exception?->getMessage())->toBe('VM Manager login failed.')
        ->and($exception?->getMessage())->not->toContain($secret)
        ->and($exception?->getPrevious())->toBeNull()
        ->and(app(VmAuthService::class)->isAuthenticated())->toBeFalse();
})->with([
    'http error' => [500, ['message' => 'request failed: response-secret-value']],
    'api failure' => [200, ['success' => false, 'message' => 'response-secret-value']],
    'missing token' => [200, ['success' => true, 'message' => 'response-secret-value']],
    'empty token' => [200, ['success' => true, 'token' => '   ', 'message' => 'response-secret-value']],
]);

test('login requires a configured URL and nonempty credentials', function () {
    config()->set('services.vm_auth.login_url', null);

    expect(fn () => app(VmAuthService::class)->login('Kubas', 'password'))
        ->toThrow(RuntimeException::class, 'VM Manager login is not configured.');

    config()->set('services.vm_auth.login_url', 'https://faster.vm-manager.org/api/login');

    expect(fn () => app(VmAuthService::class)->login('', 'password'))
        ->toThrow(RuntimeException::class, 'VM Manager credentials are required.')
        ->and(fn () => app(VmAuthService::class)->login('Kubas', ''))
        ->toThrow(RuntimeException::class, 'VM Manager credentials are required.');

    Http::assertNothingSent();
});

test('session token takes precedence over a legacy fallback and logout removes it', function () {
    $service = app(VmAuthService::class);
    $sessionToken = 'session-token';
    $fallbackToken = 'legacy-token';

    session()->put('vm_auth.token', Crypt::encryptString($sessionToken));

    expect($service->token($fallbackToken))->toBe($sessionToken)
        ->and($service->isAuthenticated())->toBeTrue();

    $service->logout();

    expect($service->isAuthenticated())->toBeFalse()
        ->and($service->token($fallbackToken))->toBe($fallbackToken);
});

test('missing or invalid session token instructs the caller to log in', function () {
    $service = app(VmAuthService::class);

    expect(fn () => $service->token())
        ->toThrow(RuntimeException::class, 'VM Manager authentication required. Please log in.');

    session()->put('vm_auth.token', 'not-an-encrypted-token');

    expect($service->isAuthenticated())->toBeFalse()
        ->and(fn () => $service->token())
        ->toThrow(RuntimeException::class, 'VM Manager authentication required. Please log in.')
        ->and(session()->has('vm_auth.token'))->toBeFalse();
});

test('unauthorized statuses invalidate the current session token', function (int $status) {
    $service = app(VmAuthService::class);
    session()->put('vm_auth.token', Crypt::encryptString('session-token'));

    expect($service->invalidateOnUnauthorized($status))->toBeTrue()
        ->and($service->isAuthenticated())->toBeFalse();
})->with([401, 403]);

test('training bar import uses the authenticated session token before its legacy token', function () {
    $url = 'https://faster.vm-manager.org/api/training';

    config()->set('services.vm_training_import.url', $url);
    config()->set('services.vm_training_import.api_token', 'legacy-token');
    session()->put('vm_auth.token', Crypt::encryptString('session-token'));

    Http::fake([
        $url => Http::response(['players' => 'invalid']),
    ]);

    expect(fn () => app(TrainingBarImportService::class)->importFromVmManager())
        ->toThrow(RuntimeException::class, 'VM Manager returned an invalid players payload.');

    Http::assertSent(function (Request $request) use ($url): bool {
        return $request->method() === 'GET'
            && $request->url() === $url
            && $request->header('Authorization') === ['Bearer session-token'];
    });
});

test('tactics authentication failure while loading existing changes stops the write', function () {
    config()->set('services.vm_tactics.url', 'https://faster.vm-manager.org/api/tactics');
    config()->set('services.vm_tactics.changes_url', 'https://faster.vm-manager.org/api/tactics/changes');
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
        ->and(app(VmAuthService::class)->isAuthenticated())->toBeFalse();
});
