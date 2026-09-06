<?php

namespace App;

use App\Models\Player;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class VmTacticsService
{
    public const STARTER_COUNT = 7;

    public const SQUAD_SIZE = 12;

    /**
     * Faster court slots: rotation 1 matching the lineup grid
     * (P1 setter back-right … P7 libero).
     *
     * @var array<int, string>
     */
    public const COURT_SLOT_KEYS = [
        1 => 'setter',
        2 => 'outside_1',
        3 => 'middle_1',
        4 => 'opposite',
        5 => 'outside_2',
        6 => 'middle_2',
        7 => 'libero',
    ];

    /**
     * @param  array{
     *     kind?: string,
     *     slots: list<array{key: string, player: ?Player}>
     * }  $recommendation
     * @param  Collection<int, Player>|null  $availablePlayers
     * @return array{
     *     matchType: string,
     *     matchId: int,
     *     player1: int|null,
     *     player2: int|null,
     *     player3: int|null,
     *     player4: int|null,
     *     player5: int|null,
     *     player6: int|null,
     *     player7: int|null,
     *     player8: int|null,
     *     player9: int|null,
     *     player10: int|null,
     *     player11: int|null,
     *     player12: int|null,
     *     block1: int,
     *     blockPassive1: int,
     *     block2: int,
     *     blockPassive2: int,
     *     block3: int,
     *     blockPassive3: int,
     * }
     */
    public function buildPayload(
        array $recommendation,
        ?Collection $availablePlayers = null,
        array $blockSettings = [],
        string $matchType = 'League',
        int $matchId = 0,
    ): array {
        $starterIds = $this->starterVmPlayerIds($recommendation);
        $availablePlayers ??= Player::query()->available()->get();
        $benchIds = $this->benchVmPlayerIds($availablePlayers, $starterIds);

        $playerIds = array_merge($starterIds, $benchIds);
        $playerIds = array_pad($playerIds, self::SQUAD_SIZE, null);

        $blocks = $this->normalizeBlockSettings($blockSettings);

        $payload = [
            'matchType' => $matchType,
            'matchId' => $matchId,
            ...$blocks,
        ];

        foreach ($playerIds as $index => $playerId) {
            $payload['player'.($index + 1)] = $playerId;
        }

        return $payload;
    }

    /**
     * @param  array{
     *     kind?: string,
     *     slots: list<array{key: string, player: ?Player}>
     * }  $recommendation
     */
    public function canPush(array $recommendation): bool
    {
        try {
            $this->starterVmPlayerIds($recommendation);
        } catch (InvalidArgumentException) {
            return false;
        }

        return true;
    }

    /**
     * @param  array{
     *     kind?: string,
     *     slots: list<array{key: string, player: ?Player}>
     * }  $recommendation
     * @param  Collection<int, Player>|null  $availablePlayers
     */
    public function pushRecommendation(
        array $recommendation,
        ?Collection $availablePlayers = null,
        string $matchType = 'League',
        int $matchId = 0,
    ): array {
        $url = config('services.vm_tactics.url');

        if (! is_string($url) || trim($url) === '') {
            throw new RuntimeException('VM Manager tactics is not configured.');
        }

        $fallbackToken = config('services.vm_tactics.api_token') ?: config('services.vm_training_import.api_token');
        $vmAuth = app(VmAuthService::class);
        $token = $vmAuth->token(is_string($fallbackToken) ? $fallbackToken : null);
        $timeout = (int) config('services.vm_tactics.timeout', 20);

        $existing = $this->fetchExistingTactic($url, $token, $timeout, $matchType, $matchId, $vmAuth);
        $payload = $this->buildPayload(
            $recommendation,
            $availablePlayers,
            $existing,
            $matchType,
            $matchId,
        );

        try {
            $response = Http::acceptJson()
                ->withToken($token)
                ->timeout($timeout)
                ->asJson()
                ->post($url, $payload);
        } catch (Throwable) {
            throw new RuntimeException('VM Manager tactics request failed.');
        }

        if ($vmAuth->invalidateOnUnauthorized($response->status())) {
            throw new RuntimeException('VM Manager authentication expired. Please log in again.');
        }

        if (! $response->successful()) {
            throw new RuntimeException('VM Manager tactics request failed.');
        }

        return $payload;
    }

    /**
     * @return array{
     *     block1: int,
     *     blockPassive1: int,
     *     block2: int,
     *     blockPassive2: int,
     *     block3: int,
     *     blockPassive3: int,
     * }
     */
    private function fetchExistingTactic(
        string $url,
        string $token,
        int $timeout,
        string $matchType,
        int $matchId,
        VmAuthService $vmAuth,
    ): array {
        try {
            $response = Http::acceptJson()
                ->withToken($token)
                ->timeout($timeout)
                ->get($url, [
                    'type' => $matchType,
                    'matchId' => (string) $matchId,
                ]);
        } catch (Throwable) {
            return [];
        }

        if ($vmAuth->invalidateOnUnauthorized($response->status())) {
            throw new RuntimeException('VM Manager authentication expired. Please log in again.');
        }

        if (! $response->successful()) {
            return [];
        }

        $json = $response->json();

        return is_array($json) ? $json : [];
    }

    /**
     * @param  array{
     *     slots: list<array{key: string, player: ?Player}>
     * }  $recommendation
     * @return list<int>
     */
    private function starterVmPlayerIds(array $recommendation): array
    {
        $slotsByKey = collect($recommendation['slots'] ?? [])
            ->keyBy(fn (array $slot): string => $slot['key']);

        $starterIds = [];

        foreach (self::COURT_SLOT_KEYS as $position => $slotKey) {
            $slot = $slotsByKey->get($slotKey);
            $player = $slot['player'] ?? null;

            if (! $player instanceof Player) {
                throw new InvalidArgumentException("Lineup slot [{$slotKey}] is empty.");
            }

            if ($player->vm_player_id === null || $player->vm_player_id < 1) {
                throw new InvalidArgumentException("Player [{$player->name}] is missing a VM player ID.");
            }

            $starterIds[$position - 1] = $player->vm_player_id;
        }

        return array_values($starterIds);
    }

    /**
     * @param  Collection<int, Player>  $availablePlayers
     * @param  list<int>  $starterVmPlayerIds
     * @return list<int>
     */
    private function benchVmPlayerIds(Collection $availablePlayers, array $starterVmPlayerIds): array
    {
        return $availablePlayers
            ->filter(function (Player $player) use ($starterVmPlayerIds): bool {
                return $player->vm_player_id !== null
                    && $player->vm_player_id >= 1
                    && ! in_array($player->vm_player_id, $starterVmPlayerIds, true);
            })
            ->sortBy([
                ['training_bar', 'asc'],
                ['name', 'asc'],
                ['id', 'asc'],
            ])
            ->values()
            ->take(self::SQUAD_SIZE - self::STARTER_COUNT)
            ->map(fn (Player $player): int => (int) $player->vm_player_id)
            ->all();
    }

    /**
     * @param  array<string, mixed>  $blockSettings
     * @return array{
     *     block1: int,
     *     blockPassive1: int,
     *     block2: int,
     *     blockPassive2: int,
     *     block3: int,
     *     blockPassive3: int,
     * }
     */
    private function normalizeBlockSettings(array $blockSettings): array
    {
        $defaults = config('services.vm_tactics.default_blocks', [
            'block1' => 7,
            'blockPassive1' => 1,
            'block2' => 7,
            'blockPassive2' => 1,
            'block3' => 7,
            'blockPassive3' => 0,
        ]);

        $normalized = [];

        foreach (['block1', 'blockPassive1', 'block2', 'blockPassive2', 'block3', 'blockPassive3'] as $key) {
            $value = $blockSettings[$key] ?? $defaults[$key] ?? 0;
            $normalized[$key] = is_numeric($value) ? (int) $value : (int) ($defaults[$key] ?? 0);
        }

        return $normalized;
    }
}
