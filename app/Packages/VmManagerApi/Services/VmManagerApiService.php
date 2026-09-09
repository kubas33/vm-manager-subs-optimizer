<?php

namespace App\Packages\VmManagerApi\Services;

use App\Packages\VmManagerApi\VmManagerApi;
use App\Packages\VmManagerApi\VmManagerEndpoints;
use Illuminate\Http\Client\Response;
use RuntimeException;

/**
 * High-level VM Manager API operations used by the application.
 */
class VmManagerApiService
{
    public function login(string $login, #[\SensitiveParameter] string $password): void
    {
        (new VmManagerApi)->init()->login($login, $password);
    }

    public function logout(): void
    {
        (new VmManagerApi)->logout();
    }

    public function isAuthenticated(): bool
    {
        return (new VmManagerApi)->isAuthenticated();
    }

    public function invalidateOnUnauthorized(int $status): bool
    {
        return (new VmManagerApi)->invalidateOnUnauthorized($status);
    }

    /**
     * @return array<string, mixed>
     */
    public function getTrainingPlayers(): array
    {
        $api = (new VmManagerApi)->init()->authorize();
        $response = $api->get(VmManagerEndpoints::TRAINING);

        $this->assertAuthorized($api, $response, 'VM Manager import request failed.');

        $players = $response->json('players');

        if (! is_array($players)) {
            throw new RuntimeException('VM Manager returned an invalid players payload.');
        }

        return $players;
    }

    /**
     * @return array<string, mixed>
     */
    public function getTactics(string $matchType = 'League', int $matchId = 0): array
    {
        $api = (new VmManagerApi)->init()->authorize();
        $response = $api->get(VmManagerEndpoints::TACTICS, [
            'type' => $matchType,
            'matchId' => (string) $matchId,
        ]);

        if ($api->invalidateOnUnauthorized($response->status())) {
            throw new RuntimeException('VM Manager authentication expired. Please log in again.');
        }

        if (! $response->successful()) {
            return [];
        }

        $json = $response->json();

        return is_array($json) ? $json : [];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function saveTactics(array $payload): Response
    {
        $api = (new VmManagerApi)->init()->authorize();
        $response = $api->post(VmManagerEndpoints::TACTICS, $payload);

        $this->assertAuthorized($api, $response, 'VM Manager tactics request failed.');

        return $response;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listTacticsChanges(string $matchType = 'League'): array
    {
        $api = (new VmManagerApi)->init()->authorize();
        $response = $api->get(VmManagerEndpoints::TACTICS_CHANGES, ['type' => $matchType]);

        $this->assertAuthorized($api, $response, 'Nie udało się połączyć z VM Managerem podczas odczytu zmian.');

        $existing = $response->json();

        if (! is_array($existing) || ! array_is_list($existing)) {
            throw new RuntimeException('VM Manager returned an invalid substitution changes list.');
        }

        /** @var list<array<string, mixed>> $existing */
        return $existing;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function createTacticsChange(array $payload): Response
    {
        $api = (new VmManagerApi)->init()->authorize();
        $response = $api->post(VmManagerEndpoints::TACTICS_CHANGES, $payload);

        if ($api->invalidateOnUnauthorized($response->status())) {
            throw new RuntimeException('VM Manager authentication expired. Please log in again.');
        }

        return $response;
    }

    public function deleteTacticsChange(int $changeId, string $matchType = 'League'): Response
    {
        if ($changeId < 1) {
            throw new RuntimeException('VM Manager change ID must be a positive integer.');
        }

        $api = (new VmManagerApi)->init()->authorize();
        $response = $api->delete(VmManagerEndpoints::TACTICS_CHANGES.'/'.$changeId, [
            'type' => $matchType,
        ]);

        $this->assertAuthorized($api, $response, 'Nie udało się usunąć zmiany w VM Managerze.');

        return $response;
    }

    public function timeout(): int
    {
        return (new VmManagerApi)->timeout();
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
        return (new VmManagerApi)->defaultBlocks();
    }

    public function importWebhookToken(): ?string
    {
        return (new VmManagerApi)->importWebhookToken();
    }

    private function assertAuthorized(VmManagerApi $api, Response $response, string $failureMessage): void
    {
        if ($api->invalidateOnUnauthorized($response->status())) {
            throw new RuntimeException('VM Manager authentication expired. Please log in again.');
        }

        if (! $response->successful()) {
            throw new RuntimeException($failureMessage);
        }
    }
}
