<?php

namespace App\Packages\VmManagerApi;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Low-level VM Manager HTTP client.
 *
 * Mirrors the NozbeApi package style: base URL from config, endpoint paths in code,
 * bearer token kept in the browser session after login.
 */
class VmManagerApi
{
    private const SESSION_TOKEN_KEY = 'vm_auth.token';

    protected string $apiUrl;

    protected int $timeout;

    protected ?string $legacyApiToken;

    protected PendingRequest $http;

    /** @var array<string, string> */
    protected array $defaultHeaders = [
        'Accept' => 'application/json',
    ];

    public function __construct()
    {
        $apiUrl = config('vm-manager.api_url');

        if (! is_string($apiUrl) || trim($apiUrl) === '') {
            throw new RuntimeException('VM Manager API URL is not configured.');
        }

        $this->apiUrl = rtrim($apiUrl, '/');
        $this->timeout = max(1, (int) config('vm-manager.timeout', 20));

        $legacy = config('vm-manager.api_token');
        $this->legacyApiToken = is_string($legacy) && trim($legacy) !== '' ? trim($legacy) : null;

        $this->http = Http::withHeaders($this->defaultHeaders)->timeout($this->timeout);
    }

    public function init(): static
    {
        $this->http = Http::withHeaders($this->defaultHeaders)->timeout($this->timeout);

        return $this;
    }

    /**
     * Authenticate against VM Manager and store an encrypted session token.
     */
    public function login(string $login, #[\SensitiveParameter] string $password): static
    {
        if (trim($login) === '' || $password === '') {
            throw new RuntimeException('VM Manager credentials are required.');
        }

        try {
            $response = $this->request('POST', VmManagerEndpoints::LOGIN, [
                'login' => $login,
                'password' => $password,
            ]);
        } catch (Throwable $exception) {
            report($exception);

            throw new RuntimeException('VM Manager login failed.');
        }

        if (in_array($response->status(), [401, 403], true)) {
            $this->logout();
            throw new RuntimeException('VM Manager login failed.');
        }

        if (! $response->successful()) {
            throw new RuntimeException('VM Manager login failed.');
        }

        $token = $response->json('token');

        if (($response->json('success') ?? null) !== true || ! is_string($token) || trim($token) === '') {
            throw new RuntimeException('VM Manager login failed.');
        }

        $this->persistAuthToken(trim($token));

        return $this;
    }

    /**
     * Attach the current bearer token to subsequent requests.
     */
    public function authorize(?string $fallbackToken = null): static
    {
        $this->http = $this->http->withToken($this->resolveToken($fallbackToken));

        return $this;
    }

    public function get(string $endpoint, array $query = []): Response
    {
        return $this->request('GET', $endpoint, $query);
    }

    public function post(string $endpoint, array $data = []): Response
    {
        return $this->request('POST', $endpoint, $data);
    }

    public function isAuthenticated(): bool
    {
        return $this->sessionToken() !== null;
    }

    public function logout(): void
    {
        session()->forget(self::SESSION_TOKEN_KEY);
    }

    public function invalidateOnUnauthorized(int $status): bool
    {
        if (! in_array($status, [401, 403], true)) {
            return false;
        }

        $this->logout();

        return true;
    }

    public function timeout(): int
    {
        return $this->timeout;
    }

    public function legacyApiToken(): ?string
    {
        return $this->legacyApiToken;
    }

    public function importWebhookToken(): ?string
    {
        $token = config('vm-manager.import_token');

        return is_string($token) && trim($token) !== '' ? trim($token) : null;
    }

    /**
     * @return array{
     *     block1: int,
     *     blockPassive1: int,
     *     block2: int,
     *     blockPassive2: int,
     *     block3: int,
     *     blockPassive3: int
     * }
     */
    public function defaultBlocks(): array
    {
        $defaults = config('vm-manager.default_blocks', []);

        return [
            'block1' => (int) ($defaults['block1'] ?? 7),
            'blockPassive1' => (int) ($defaults['blockPassive1'] ?? 1),
            'block2' => (int) ($defaults['block2'] ?? 7),
            'blockPassive2' => (int) ($defaults['blockPassive2'] ?? 1),
            'block3' => (int) ($defaults['block3'] ?? 7),
            'blockPassive3' => (int) ($defaults['blockPassive3'] ?? 0),
        ];
    }

    public function resolveToken(?string $fallbackToken = null): string
    {
        $sessionToken = $this->sessionToken();

        if ($sessionToken !== null) {
            return $sessionToken;
        }

        if (is_string($fallbackToken) && trim($fallbackToken) !== '') {
            return trim($fallbackToken);
        }

        if ($this->legacyApiToken !== null) {
            return $this->legacyApiToken;
        }

        throw new RuntimeException('VM Manager authentication required. Please log in.');
    }

    private function url(string $endpoint): string
    {
        return $this->apiUrl.'/'.ltrim($endpoint, '/');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function request(string $method, string $endpoint, array $data): Response
    {
        $url = $this->url($endpoint);
        $startedAt = microtime(true);

        $this->logRequest($method, $url, $data);

        try {
            $response = match ($method) {
                'GET' => $this->http->acceptJson()->get($url, $data),
                'POST' => $this->http->acceptJson()->asJson()->post($url, $data),
                default => throw new RuntimeException("Unsupported VM Manager HTTP method [{$method}]."),
            };
        } catch (Throwable $exception) {
            Log::channel('vm_manager')->error('VM Manager request failed', [
                ...$this->requestContext($method, $url, $data),
                'duration_ms' => $this->durationInMilliseconds($startedAt),
                'exception' => $exception::class,
                'error' => $exception->getMessage(),
            ]);

            throw $exception;
        }

        Log::channel('vm_manager')->debug('VM Manager response', [
            ...$this->requestContext($method, $url, $data),
            'status' => $response->status(),
            'successful' => $response->successful(),
            'duration_ms' => $this->durationInMilliseconds($startedAt),
            'body' => $this->responseBody($url, $response),
        ]);

        return $response;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function logRequest(string $method, string $url, array $data): void
    {
        Log::channel('vm_manager')->debug('VM Manager request', $this->requestContext($method, $url, $data));
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function requestContext(string $method, string $url, array $data): array
    {
        return [
            'method' => $method,
            'url' => $this->fullUrl($method, $url, $data),
            'query' => $method === 'GET' ? $this->redactSensitiveData($data) : [],
            'payload' => $method === 'POST' ? $this->redactSensitiveData($data) : [],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function fullUrl(string $method, string $url, array $data): string
    {
        if ($method !== 'GET' || $data === []) {
            return $url;
        }

        return $url.'?'.http_build_query($data);
    }

    private function durationInMilliseconds(float $startedAt): float
    {
        return round((microtime(true) - $startedAt) * 1000, 2);
    }

    private function responseBody(string $url, Response $response): mixed
    {
        if (str_ends_with($url, '/'.VmManagerEndpoints::LOGIN)) {
            return '[REDACTED]';
        }

        $json = $response->json();

        return $this->redactSensitiveData($json ?? $response->body());
    }

    private function redactSensitiveData(mixed $value, ?string $key = null): mixed
    {
        if ($key !== null && $this->isSensitiveKey($key)) {
            return '[REDACTED]';
        }

        if (is_array($value)) {
            $redacted = [];

            foreach ($value as $itemKey => $itemValue) {
                $redacted[$itemKey] = $this->redactSensitiveData($itemValue, (string) $itemKey);
            }

            return $redacted;
        }

        if (is_string($value) && strlen($value) > 10000) {
            return substr($value, 0, 10000).'...[truncated]';
        }

        return $value;
    }

    private function isSensitiveKey(string $key): bool
    {
        $normalizedKey = strtolower(str_replace(['-', '_'], '', $key));

        return str_contains($normalizedKey, 'password')
            || str_contains($normalizedKey, 'token')
            || str_contains($normalizedKey, 'authorization')
            || str_contains($normalizedKey, 'secret');
    }

    private function persistAuthToken(string $token): void
    {
        session()->put(self::SESSION_TOKEN_KEY, Crypt::encryptString($token));
    }

    private function sessionToken(): ?string
    {
        $encryptedToken = session()->get(self::SESSION_TOKEN_KEY);

        if (! is_string($encryptedToken) || $encryptedToken === '') {
            return null;
        }

        try {
            $token = Crypt::decryptString($encryptedToken);
        } catch (DecryptException) {
            $this->logout();

            return null;
        }

        return is_string($token) && trim($token) !== '' ? $token : null;
    }
}
