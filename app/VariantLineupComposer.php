<?php

namespace App;

use App\Enums\PlayerPosition;
use App\Models\Player;

final class VariantLineupComposer
{
    /**
     * @param  array<string, mixed>  $baseRecommendation
     * @param  array<string, mixed>  $plan
     * @return array{
     *     lineup: array<string, array<string, mixed>>,
     *     bench: list<Player|null>,
     *     starter_vm_player_ids: list<int>,
     *     bench_vm_player_ids: list<int>,
     *     send_blockers: list<array<string, int|string|null>>,
     *     is_sendable: bool
     * }
     */
    public function compose(array $baseRecommendation, array $plan): array
    {
        $sendBlockers = [];
        $lineup = $this->baseLineup($baseRecommendation);
        $planSlots = collect($plan['slots'] ?? [])
            ->filter(fn (mixed $slot): bool => is_array($slot))
            ->sortBy(fn (array $slot): array => [(int) ($slot['slot_number'] ?? PHP_INT_MAX), (string) ($slot['position'] ?? '')])
            ->values();
        $players = $this->availablePlayersForPlan($planSlots->all());

        $planSlots
            ->groupBy(fn (array $slot): string => (string) ($slot['position'] ?? ''))
            ->each(function ($slots, string $position) use (&$lineup, $players, &$sendBlockers): void {
                $courtSlotKeys = $this->courtSlotKeysForPosition($position);

                foreach ($slots->values() as $index => $slot) {
                    $slotKey = $courtSlotKeys[$index] ?? null;

                    if ($slotKey === null) {
                        $this->addBlocker($sendBlockers, 'missing_lineup_slot', 'Wariant wskazuje pozycję bez miejsca w pełnym składzie.', slotKey: null);

                        continue;
                    }

                    $starter = $slot['starter'] ?? null;
                    $playerId = is_array($starter) ? $this->positiveId($starter['id'] ?? null) : null;
                    $playerName = is_array($starter) ? (string) ($starter['name'] ?? 'Nieznany zawodnik') : 'Nieznany zawodnik';

                    if ($playerId === null || ! isset($players[$playerId])) {
                        $this->addBlocker($sendBlockers, 'unavailable_player', "{$playerName} — zawodnik nie jest dostępny.", $playerId, $playerName, $slotKey);

                        continue;
                    }

                    $lineup[$slotKey] = [
                        ...$lineup[$slotKey],
                        'player' => $players[$playerId],
                        'source' => 'optimized',
                    ];
                }
            });

        $this->replaceConflictingBaseStarters($lineup, [
            ...$this->optimizedStarterPlayerIds($planSlots->all()),
            ...$this->requiredBenchPlayerIds($planSlots->all()),
        ]);
        $starterPlayerIds = $this->validateLineup($lineup, $sendBlockers);
        $benchPlayers = $this->benchPlayers($planSlots->all(), $players, $starterPlayerIds, $sendBlockers);
        $this->validateVmIds($lineup, $benchPlayers, $sendBlockers);

        $starterVmPlayerIds = collect(VmTacticsService::COURT_SLOT_KEYS)
            ->map(fn (string $slotKey): ?int => $this->validVmId($lineup[$slotKey]['player'] ?? null))
            ->filter()
            ->values()
            ->all();
        $benchVmPlayerIds = collect($benchPlayers)
            ->filter()
            ->map(fn (Player $player): ?int => $this->validVmId($player))
            ->filter()
            ->values()
            ->all();

        return [
            'lineup' => $lineup,
            'bench' => array_pad(
                array_slice($benchPlayers, 0, VmTacticsService::SQUAD_SIZE - VmTacticsService::STARTER_COUNT),
                VmTacticsService::SQUAD_SIZE - VmTacticsService::STARTER_COUNT,
                null,
            ),
            'starter_vm_player_ids' => $starterVmPlayerIds,
            'bench_vm_player_ids' => $benchVmPlayerIds,
            'send_blockers' => $sendBlockers,
            'is_sendable' => $sendBlockers === [],
        ];
    }

    /**
     * @param  array<string, mixed>  $baseRecommendation
     * @return array<string, array<string, mixed>>
     */
    private function baseLineup(array $baseRecommendation): array
    {
        $baseSlots = collect($baseRecommendation['slots'] ?? [])
            ->filter(fn (mixed $slot): bool => is_array($slot) && is_string($slot['key'] ?? null))
            ->keyBy('key');

        $lineup = [];

        foreach (VmTacticsService::COURT_SLOT_KEYS as $slotKey) {
            $slot = $baseSlots->get($slotKey, []);
            $lineup[$slotKey] = [
                ...$slot,
                'key' => $slotKey,
                'player' => $slot['player'] ?? null,
                'source' => 'base',
            ];
        }

        return $lineup;
    }

    /**
     * @param  list<array<string, mixed>>  $planSlots
     * @return array<int, Player>
     */
    private function availablePlayersForPlan(array $planSlots): array
    {
        $ids = collect($planSlots)
            ->flatMap(function (array $slot): array {
                $starterId = is_array($slot['starter'] ?? null) ? $this->positiveId($slot['starter']['id'] ?? null) : null;
                $substitutionIds = collect($slot['sets'] ?? [])
                    ->filter(fn (mixed $set): bool => is_array($set) && is_array($set['substitution_player'] ?? null))
                    ->map(fn (array $set): ?int => $this->positiveId($set['substitution_player']['id'] ?? null))
                    ->filter()
                    ->all();

                return array_filter([$starterId, ...$substitutionIds]);
            })
            ->unique()
            ->values()
            ->all();

        return Player::query()->available()->whereKey($ids)->get()->keyBy('id')->all();
    }

    /**
     * An optimized plan can reuse a player who appears in another, untouched base
     * slot. Replace only that base slot, so the composed squad stays legal without
     * overriding an optimizer-selected starter or reserve.
     *
     * @param  array<string, array<string, mixed>>  $lineup
     * @param  list<int>  $benchPlayerIds
     */
    private function replaceConflictingBaseStarters(array &$lineup, array $protectedPlayerIds): void
    {
        $protectedPlayerIds = array_values(array_unique($protectedPlayerIds));

        if ($protectedPlayerIds === []) {
            return;
        }

        foreach ($lineup as $slotKey => $slot) {
            $player = $slot['player'] ?? null;

            if ($slot['source'] !== 'base' || ! $player instanceof Player || ! in_array($player->id, $protectedPlayerIds, true)) {
                continue;
            }

            $occupiedPlayerIds = collect($lineup)
                ->except($slotKey)
                ->map(fn (array $lineupSlot): ?int => ($lineupSlot['player'] ?? null) instanceof Player ? $lineupSlot['player']->id : null)
                ->filter()
                ->all();

            $replacement = Player::query()
                ->available()
                ->where('position', $player->position->value)
                ->where('vm_player_id', '>', 0)
                ->whereNotIn('id', [...$occupiedPlayerIds, ...$protectedPlayerIds])
                ->orderBy('training_bar')
                ->orderBy('name')
                ->orderBy('id')
                ->first();

            if ($replacement instanceof Player) {
                $lineup[$slotKey]['player'] = $replacement;
            }
        }
    }

    /**
     * @param  list<array<string, mixed>>  $planSlots
     * @return list<int>
     */
    private function requiredBenchPlayerIds(array $planSlots): array
    {
        return collect($planSlots)
            ->flatMap(function (array $slot): array {
                return collect($slot['sets'] ?? [])
                    ->filter(fn (mixed $set): bool => is_array($set) && is_array($set['substitution_player'] ?? null))
                    ->map(fn (array $set): ?int => $this->positiveId($set['substitution_player']['id'] ?? null))
                    ->filter()
                    ->all();
            })
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  list<array<string, mixed>>  $planSlots
     * @return list<int>
     */
    private function optimizedStarterPlayerIds(array $planSlots): array
    {
        return collect($planSlots)
            ->map(fn (array $slot): ?int => is_array($slot['starter'] ?? null) ? $this->positiveId($slot['starter']['id'] ?? null) : null)
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  array<string, array<string, mixed>>  $lineup
     * @param  list<array<string, int|string|null>>  $sendBlockers
     * @return list<int>
     */
    private function validateLineup(array $lineup, array &$sendBlockers): array
    {
        $playerIds = [];

        foreach (VmTacticsService::COURT_SLOT_KEYS as $slotKey) {
            $player = $lineup[$slotKey]['player'] ?? null;

            if (! $player instanceof Player) {
                $this->addBlocker($sendBlockers, 'missing_lineup_slot', 'Brak zawodnika dla pola '.str_replace('_', ' ', $slotKey).'.', slotKey: $slotKey);

                continue;
            }

            if (in_array($player->id, $playerIds, true)) {
                $this->addBlocker($sendBlockers, 'duplicate_starter', "{$player->name} występuje więcej niż raz w składzie.", $player->id, $player->name, $slotKey);
            }

            $playerIds[] = $player->id;
        }

        return $playerIds;
    }

    /**
     * @param  list<array<string, mixed>>  $planSlots
     * @param  array<int, Player>  $players
     * @param  list<int>  $starterPlayerIds
     * @param  list<array<string, int|string|null>>  $sendBlockers
     * @return list<Player|null>
     */
    private function benchPlayers(array $planSlots, array $players, array $starterPlayerIds, array &$sendBlockers): array
    {
        $benchPlayers = [];

        foreach ($planSlots as $slot) {
            foreach ($slot['sets'] ?? [] as $set) {
                $substitution = is_array($set) ? ($set['substitution_player'] ?? null) : null;

                if (! is_array($substitution)) {
                    continue;
                }

                $playerId = $this->positiveId($substitution['id'] ?? null);
                $playerName = (string) ($substitution['name'] ?? 'Nieznany zawodnik');

                if ($playerId === null || ! isset($players[$playerId])) {
                    $this->addBlocker($sendBlockers, 'unavailable_player', "{$playerName} — zawodnik rezerwowy nie jest dostępny.", $playerId, $playerName);

                    continue;
                }

                $player = $players[$playerId];

                if (in_array($playerId, $starterPlayerIds, true)) {
                    $this->addBlocker($sendBlockers, 'starter_on_bench', "{$player->name} jest jednocześnie starterem i rezerwowym.", $playerId, $player->name);

                    continue;
                }

                $benchPlayers[$playerId] ??= $player;
            }
        }

        if (count($benchPlayers) > VmTacticsService::SQUAD_SIZE - VmTacticsService::STARTER_COUNT) {
            $this->addBlocker($sendBlockers, 'bench_overflow', 'Wariant wymaga więcej niż pięciu zawodników na ławce.');
        }

        return array_values($benchPlayers);
    }

    /**
     * @param  array<string, array<string, mixed>>  $lineup
     * @param  list<Player|null>  $benchPlayers
     * @param  list<array<string, int|string|null>>  $sendBlockers
     */
    private function validateVmIds(array $lineup, array $benchPlayers, array &$sendBlockers): void
    {
        $seenVmIds = [];

        foreach ($lineup as $slotKey => $slot) {
            $this->validatePlayerVmId($slot['player'] ?? null, $slotKey, $seenVmIds, $sendBlockers);
        }

        foreach ($benchPlayers as $player) {
            $this->validatePlayerVmId($player, null, $seenVmIds, $sendBlockers);
        }
    }

    /**
     * @param  list<int>  $seenVmIds
     * @param  list<array<string, int|string|null>>  $sendBlockers
     */
    private function validatePlayerVmId(mixed $player, ?string $slotKey, array &$seenVmIds, array &$sendBlockers): void
    {
        if (! $player instanceof Player) {
            return;
        }

        if ($player->vm_player_id === null) {
            $this->addBlocker($sendBlockers, 'missing_vm_id', "{$player->name} — brak ID VM.", $player->id, $player->name, $slotKey);

            return;
        }

        $vmPlayerId = $this->validVmId($player);

        if ($vmPlayerId === null) {
            $this->addBlocker($sendBlockers, 'invalid_vm_id', "{$player->name} — nieprawidłowe ID VM.", $player->id, $player->name, $slotKey);

            return;
        }

        if (in_array($vmPlayerId, $seenVmIds, true)) {
            $this->addBlocker($sendBlockers, 'duplicate_vm_id', "{$player->name} — ID VM jest używane przez więcej niż jednego zawodnika.", $player->id, $player->name, $slotKey);
        }

        $seenVmIds[] = $vmPlayerId;
    }

    /** @return list<string> */
    private function courtSlotKeysForPosition(string $position): array
    {
        return collect($this->courtSlotPositions())
            ->filter(fn (string $positionValue): bool => $positionValue === $position)
            ->keys()
            ->all();
    }

    /** @return array<string, string> */
    private function courtSlotPositions(): array
    {
        return [
            'setter' => PlayerPosition::Setter->value,
            'outside_1' => PlayerPosition::OutsideHitter->value,
            'middle_1' => PlayerPosition::MiddleBlocker->value,
            'opposite' => PlayerPosition::Opposite->value,
            'outside_2' => PlayerPosition::OutsideHitter->value,
            'middle_2' => PlayerPosition::MiddleBlocker->value,
            'libero' => PlayerPosition::Libero->value,
        ];
    }

    private function positiveId(mixed $value): ?int
    {
        if (! is_int($value) && ! (is_string($value) && ctype_digit($value))) {
            return null;
        }

        return (int) $value > 0 ? (int) $value : null;
    }

    private function validVmId(mixed $player): ?int
    {
        return $player instanceof Player && is_int($player->vm_player_id) && $player->vm_player_id > 0
            ? $player->vm_player_id
            : null;
    }

    /**
     * @param  list<array<string, int|string|null>>  $sendBlockers
     */
    private function addBlocker(array &$sendBlockers, string $code, string $message, ?int $playerId = null, ?string $playerName = null, ?string $slotKey = null): void
    {
        $sendBlockers[] = [
            'code' => $code,
            'message' => $message,
            'player_id' => $playerId,
            'player_name' => $playerName,
            'slot_key' => $slotKey,
        ];
    }
}
