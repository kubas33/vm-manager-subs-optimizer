<?php

namespace App;

use App\Models\Player;
use App\Packages\VmManagerApi\Services\VmManagerApiService;
use Illuminate\Support\Collection;
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
     * @param  list<int>  $benchVmPlayerIds
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
        array $benchVmPlayerIds = [],
    ): array {
        $starterIds = $this->starterVmPlayerIds($recommendation);

        return $this->buildPayloadFromVmPlayerIds(
            starterVmPlayerIds: $starterIds,
            benchVmPlayerIds: $benchVmPlayerIds,
            blockSettings: $blockSettings,
            matchType: $matchType,
            matchId: $matchId,
        );
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
        $api = app(VmManagerApiService::class);
        $starterVmPlayerIds = $this->starterVmPlayerIds($recommendation);
        $payload = $this->buildPayloadFromVmPlayerIds(
            starterVmPlayerIds: $starterVmPlayerIds,
            benchVmPlayerIds: $this->benchVmPlayerIdsFromPlayers($availablePlayers, $starterVmPlayerIds),
            blockSettings: [],
            matchType: $matchType,
            matchId: $matchId,
        );

        try {
            $api->saveTactics($payload);
        } catch (Throwable $exception) {
            if ($exception instanceof RuntimeException) {
                throw $exception;
            }

            throw new RuntimeException('VM Manager tactics request failed.');
        }

        return $payload;
    }

    /**
     * Save the provided starting lineup with the reserves required by a
     * selected substitution plan.
     *
     * @param  list<array<string, mixed>>  $substitutionPayloads
     * @param  list<int>  $starterVmPlayerIds
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
    public function pushVariantTactics(
        array $substitutionPayloads,
        array $starterVmPlayerIds,
        string $matchType = 'League',
        int $matchId = 0,
    ): array {
        $benchVmPlayerIds = $this->substitutionVmPlayerIds($substitutionPayloads, 'playerIn');
        $api = app(VmManagerApiService::class);
        $starterVmPlayerIds = $this->normalizeStarterVmPlayerIds($starterVmPlayerIds);

        $payload = $this->buildPayloadFromVmPlayerIds(
            starterVmPlayerIds: $starterVmPlayerIds,
            benchVmPlayerIds: $benchVmPlayerIds,
            blockSettings: [],
            matchType: $matchType,
            matchId: $matchId,
        );

        try {
            $api->saveTactics($payload);
        } catch (Throwable $exception) {
            if ($exception instanceof RuntimeException) {
                throw $exception;
            }

            throw new RuntimeException('VM Manager tactics request failed.');
        }

        return $payload;
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
     * @param  list<int>  $starterVmPlayerIds
     * @param  list<int>  $benchVmPlayerIds
     * @param  array<string, mixed>  $blockSettings
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
    private function buildPayloadFromVmPlayerIds(
        array $starterVmPlayerIds,
        array $benchVmPlayerIds,
        array $blockSettings,
        string $matchType,
        int $matchId,
    ): array {
        $benchVmPlayerIds = $this->normalizeBenchVmPlayerIds($benchVmPlayerIds, $starterVmPlayerIds);
        $playerIds = array_pad(array_merge($starterVmPlayerIds, $benchVmPlayerIds), self::SQUAD_SIZE, null);
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
     * @param  list<array<string, mixed>>  $substitutionPayloads
     * @return list<int>
     */
    private function substitutionVmPlayerIds(array $substitutionPayloads, string $field): array
    {
        $playerIds = [];

        foreach ($substitutionPayloads as $payload) {
            $playerId = $this->positiveVmPlayerId($payload[$field] ?? null);

            if ($playerId === null) {
                throw new InvalidArgumentException('Wybrany wariant zmian zawiera nieprawidłowe ID zawodnika VM.');
            }

            if (! in_array($playerId, $playerIds, true)) {
                $playerIds[] = $playerId;
            }
        }

        return $playerIds;
    }

    /**
     * @param  list<int>  $benchVmPlayerIds
     * @param  list<int>  $starterVmPlayerIds
     * @return list<int>
     */
    private function normalizeBenchVmPlayerIds(array $benchVmPlayerIds, array $starterVmPlayerIds): array
    {
        $normalizedBenchVmPlayerIds = [];

        foreach ($benchVmPlayerIds as $playerId) {
            $vmPlayerId = $this->positiveVmPlayerId($playerId);

            if ($vmPlayerId === null) {
                throw new InvalidArgumentException('Każdy rezerwowy musi mieć dodatnie ID VM.');
            }

            if (in_array($vmPlayerId, $starterVmPlayerIds, true)) {
                throw new InvalidArgumentException('Zawodnik z boiska nie może jednocześnie zajmować ławki rezerwowych.');
            }

            if (! in_array($vmPlayerId, $normalizedBenchVmPlayerIds, true)) {
                $normalizedBenchVmPlayerIds[] = $vmPlayerId;
            }
        }

        if (count($normalizedBenchVmPlayerIds) > self::SQUAD_SIZE - self::STARTER_COUNT) {
            throw new InvalidArgumentException('Wybrany wariant wymaga więcej niż pięciu rezerwowych w VM.');
        }

        return $normalizedBenchVmPlayerIds;
    }

    /**
     * @param  list<int>  $starterVmPlayerIds
     * @return list<int>
     */
    private function normalizeStarterVmPlayerIds(array $starterVmPlayerIds): array
    {
        $normalizedStarterVmPlayerIds = [];

        foreach ($starterVmPlayerIds as $playerId) {
            $vmPlayerId = $this->positiveVmPlayerId($playerId);

            if ($vmPlayerId === null) {
                throw new InvalidArgumentException('Każdy starter musi mieć dodatnie ID VM.');
            }

            if (in_array($vmPlayerId, $normalizedStarterVmPlayerIds, true)) {
                throw new InvalidArgumentException('Podstawowy skład nie może zawierać zduplikowanych zawodników.');
            }

            $normalizedStarterVmPlayerIds[] = $vmPlayerId;
        }

        if (count($normalizedStarterVmPlayerIds) !== self::STARTER_COUNT) {
            throw new InvalidArgumentException('Podstawowy skład musi zawierać siedmiu zawodników.');
        }

        return $normalizedStarterVmPlayerIds;
    }

    /**
     * @param  Collection<int, Player>|null  $availablePlayers
     * @param  list<int>  $starterVmPlayerIds
     * @return list<int>
     */
    private function benchVmPlayerIdsFromPlayers(?Collection $availablePlayers, array $starterVmPlayerIds): array
    {
        if ($availablePlayers === null) {
            return [];
        }

        return $availablePlayers
            ->filter(fn (Player $player): bool => $player->vm_player_id !== null)
            ->reject(fn (Player $player): bool => in_array($player->vm_player_id, $starterVmPlayerIds, true))
            ->sortBy(fn (Player $player): array => [$player->training_bar, $player->name, $player->id])
            ->take(self::SQUAD_SIZE - self::STARTER_COUNT)
            ->pluck('vm_player_id')
            ->map(fn (int $playerId): int => $playerId)
            ->values()
            ->all();
    }

    private function positiveVmPlayerId(mixed $value): ?int
    {
        if (is_int($value) && $value > 0) {
            return $value;
        }

        if (is_string($value) && preg_match('/^[1-9][0-9]*$/', $value) === 1) {
            return (int) $value;
        }

        return null;
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
        $defaults = app(VmManagerApiService::class)->defaultBlocks();

        $normalized = [];

        foreach (['block1', 'blockPassive1', 'block2', 'blockPassive2', 'block3', 'blockPassive3'] as $key) {
            $value = $blockSettings[$key] ?? $defaults[$key] ?? 0;
            $normalized[$key] = is_numeric($value) ? (int) $value : (int) ($defaults[$key] ?? 0);
        }

        return $normalized;
    }
}
