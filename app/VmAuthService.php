<?php

namespace App;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

final class VmAuthService
{
    private const SESSION_TOKEN_KEY = 'vm_auth.token';

    /**
     * Log in to VM Manager and keep its token in the current browser session.
     */
    public function login(string $login, #[\SensitiveParameter] string $password): void
    {
        $url = config('services.vm_auth.login_url');

        if (! is_string($url) || trim($url) === '') {
            throw new RuntimeException('VM Manager login is not configured.');
        }

        if (trim($login) === '' || $password === '') {
            throw new RuntimeException('VM Manager credentials are required.');
        }

        try {
            $response = Http::acceptJson()
                ->asJson()
                ->timeout((int) config('services.vm_auth.timeout', 20))
                ->post($url, [
                    'login' => $login,
                    'password' => $password,
                ]);
        } catch (Throwable) {
            throw new RuntimeException('VM Manager login failed.');
        }

        if ($this->invalidateOnUnauthorized($response->status())) {
            throw new RuntimeException('VM Manager login failed.');
        }

        if (! $response->successful()) {
            throw new RuntimeException('VM Manager login failed.');
        }

        $token = $response->json('token');

        if (($response->json('success') ?? null) !== true || ! is_string($token) || trim($token) === '') {
            throw new RuntimeException('VM Manager login failed.');
        }

        session()->put(self::SESSION_TOKEN_KEY, Crypt::encryptString(trim($token)));
    }

    /**
     * Resolve the current session token, falling back to a configured legacy token.
     *
     * @throws RuntimeException when no usable token is available.
     */
    public function token(?string $fallback = null): string
    {
        $sessionToken = $this->sessionToken();

        if ($sessionToken !== null) {
            return $sessionToken;
        }

        if (is_string($fallback) && trim($fallback) !== '') {
            return $fallback;
        }

        throw new RuntimeException('VM Manager authentication required. Please log in.');
    }

    public function isAuthenticated(): bool
    {
        return $this->sessionToken() !== null;
    }

    public function logout(): void
    {
        session()->forget(self::SESSION_TOKEN_KEY);
    }

    /**
     * Forget a session token when VM Manager reports an authorization failure.
     *
     * @return bool whether the status represents an authorization failure
     */
    public function invalidateOnUnauthorized(int $status): bool
    {
        if (! in_array($status, [401, 403], true)) {
            return false;
        }

        $this->logout();

        return true;
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
