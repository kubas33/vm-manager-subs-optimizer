<?php

namespace App;

use App\Enums\PlayerPosition;
use App\Models\Player;
use Illuminate\Support\Collection;

class LineupRecommendationService
{
    public const ALTERNATIVE_SWAP_MAX_TRAINING_BAR = 60;

    public const ALTERNATIVE_SIMILAR_BAR_TOLERANCE = 5;

    public const ALTERNATIVE_COUNT = 3;

    public const MIN_ALTERNATIVE_CHANGES = 2;

    public const CANDIDATES_PER_SINGLE_POSITION = 4;

    public const CANDIDATES_PER_PAIR_POSITION = 5;

    public const MAX_LINEUP_CANDIDATES = 500;

    /**
     * @return array{
     *     is_complete: bool,
     *     missing_slots: list<array{slot: string, label: string, required: int, available: int}>,
     *     recommendations: list<array{
     *         kind: 'primary'|'alternative',
     *         total_training_bar: int,
     *         swap_description: ?string,
     *         changed_slot_keys: list<string>,
     *         slots: list<array{
     *             key: string,
     *             label: string,
     *             abbreviation: string,
     *             grid_row: int,
     *             grid_column: int,
     *             player: ?Player,
     *             training_bar: ?int,
     *         }>
     *     }>
     * }
     */
    public function recommend(?Collection $players = null): array
    {
        $players ??= Player::query()->active()->get();

        if ($players->isEmpty()) {
            return [
                'is_complete' => false,
                'missing_slots' => [],
                'recommendations' => [],
            ];
        }

        $pools = $this->buildPools($players);
        $slotDefinitions = $this->slotDefinitions();
        $primarySlots = $this->buildSlots($slotDefinitions, $pools);
        $primary = $this->buildRecommendation('primary', $primarySlots);

        $alternatives = $this->buildAlternatives($primarySlots, $pools, $slotDefinitions);

        return [
            'is_complete' => $this->isComplete($primarySlots),
            'missing_slots' => $this->missingSlots($slotDefinitions, $pools),
            'recommendations' => array_values(array_merge([$primary], $alternatives)),
        ];
    }

    /**
     * @return list<array{
     *     key: string,
     *     label: string,
     *     abbreviation: string,
     *     grid_row: int,
     *     grid_column: int,
     *     position: PlayerPosition,
     *     pool_index: int,
     * }>
     */
    private function slotDefinitions(): array
    {
        return [
            [
                'key' => 'opposite',
                'label' => 'Atakujący',
                'abbreviation' => 'At',
                'grid_row' => 1,
                'grid_column' => 1,
                'position' => PlayerPosition::Opposite,
                'pool_index' => 0,
            ],
            [
                'key' => 'middle_1',
                'label' => 'Środkowy',
                'abbreviation' => 'Śr',
                'grid_row' => 1,
                'grid_column' => 2,
                'position' => PlayerPosition::MiddleBlocker,
                'pool_index' => 0,
            ],
            [
                'key' => 'outside_1',
                'label' => 'Przyjmujący 1',
                'abbreviation' => 'P',
                'grid_row' => 1,
                'grid_column' => 3,
                'position' => PlayerPosition::OutsideHitter,
                'pool_index' => 0,
            ],
            [
                'key' => 'outside_2',
                'label' => 'Przyjmujący 2',
                'abbreviation' => 'P',
                'grid_row' => 2,
                'grid_column' => 1,
                'position' => PlayerPosition::OutsideHitter,
                'pool_index' => 1,
            ],
            [
                'key' => 'middle_2',
                'label' => 'Środkowy',
                'abbreviation' => 'Śr',
                'grid_row' => 2,
                'grid_column' => 2,
                'position' => PlayerPosition::MiddleBlocker,
                'pool_index' => 1,
            ],
            [
                'key' => 'setter',
                'label' => 'Rozgrywający',
                'abbreviation' => 'R',
                'grid_row' => 2,
                'grid_column' => 3,
                'position' => PlayerPosition::Setter,
                'pool_index' => 0,
            ],
            [
                'key' => 'libero',
                'label' => 'Libero',
                'abbreviation' => 'L',
                'grid_row' => 3,
                'grid_column' => 2,
                'position' => PlayerPosition::Libero,
                'pool_index' => 0,
            ],
        ];
    }

    /**
     * @param  Collection<int, Player>  $players
     * @return array<string, Collection<int, Player>>
     */
    private function buildPools(Collection $players): array
    {
        return $players
            ->groupBy(fn (Player $player): string => $player->position->value)
            ->map(fn (Collection $group): Collection => $group
                ->sortBy([
                    ['training_bar', 'asc'],
                    ['name', 'asc'],
                    ['id', 'asc'],
                ])
                ->values())
            ->all();
    }

    /**
     * @param  list<array{key: string, label: string, abbreviation: string, grid_row: int, grid_column: int, position: PlayerPosition, pool_index: int}>  $slotDefinitions
     * @param  array<string, Collection<int, Player>>  $pools
     * @return list<array{
     *     key: string,
     *     label: string,
     *     abbreviation: string,
     *     grid_row: int,
     *     grid_column: int,
     *     player: ?Player,
     *     training_bar: ?int,
     * }>
     */
    private function buildSlots(array $slotDefinitions, array $pools): array
    {
        return collect($slotDefinitions)
            ->map(function (array $definition) use ($pools): array {
                $player = ($pools[$definition['position']->value] ?? collect())->get($definition['pool_index']);

                return $this->slotFromPlayer($definition, $player);
            })
            ->values()
            ->all();
    }

    /**
     * @param  array{key: string, label: string, abbreviation: string, grid_row: int, grid_column: int, position: PlayerPosition, pool_index: int}  $definition
     * @return array{
     *     key: string,
     *     label: string,
     *     abbreviation: string,
     *     grid_row: int,
     *     grid_column: int,
     *     player: ?Player,
     *     training_bar: ?int,
     * }
     */
    private function slotFromPlayer(array $definition, ?Player $player): array
    {
        return [
            'key' => $definition['key'],
            'label' => $definition['label'],
            'abbreviation' => $definition['abbreviation'],
            'grid_row' => $definition['grid_row'],
            'grid_column' => $definition['grid_column'],
            'player' => $player,
            'training_bar' => $player?->training_bar,
        ];
    }

    /**
     * @param  list<array{key: string, label: string, abbreviation: string, grid_row: int, grid_column: int, player: ?Player, training_bar: ?int}>  $slots
     */
    private function isComplete(array $slots): bool
    {
        return collect($slots)->every(fn (array $slot): bool => $slot['player'] !== null);
    }

    /**
     * @param  list<array{key: string, label: string, abbreviation: string, grid_row: int, grid_column: int, position: PlayerPosition, pool_index: int}>  $slotDefinitions
     * @param  array<string, Collection<int, Player>>  $pools
     * @return list<array{slot: string, label: string, required: int, available: int}>
     */
    private function missingSlots(array $slotDefinitions, array $pools): array
    {
        $requiredByPosition = collect($slotDefinitions)
            ->groupBy(fn (array $definition): string => $definition['position']->value)
            ->map(fn (Collection $definitions): int => $definitions->count());

        $missing = [];

        foreach ($requiredByPosition as $positionValue => $required) {
            $available = ($pools[$positionValue] ?? collect())->count();

            if ($available >= $required) {
                continue;
            }

            $position = PlayerPosition::from($positionValue);

            foreach ($slotDefinitions as $definition) {
                if ($definition['position'] !== $position) {
                    continue;
                }

                if (($pools[$positionValue] ?? collect())->has($definition['pool_index'])) {
                    continue;
                }

                $missing[] = [
                    'slot' => $definition['key'],
                    'label' => $definition['label'],
                    'required' => $required,
                    'available' => $available,
                ];
            }
        }

        return $missing;
    }

    /**
     * @param  list<array{key: string, label: string, abbreviation: string, grid_row: int, grid_column: int, player: ?Player, training_bar: ?int}>  $slots
     * @return array{
     *     kind: 'primary'|'alternative',
     *     total_training_bar: int,
     *     swap_description: ?string,
     *     changed_slot_keys: list<string>,
     *     slots: list<array{
     *         key: string,
     *         label: string,
     *         abbreviation: string,
     *         grid_row: int,
     *         grid_column: int,
     *         player: ?Player,
     *         training_bar: ?int,
     *     }>
     * }
     */
    private function buildRecommendation(
        string $kind,
        array $slots,
        ?string $swapDescription = null,
        array $changedSlotKeys = [],
    ): array {
        return [
            'kind' => $kind,
            'total_training_bar' => collect($slots)->sum('training_bar'),
            'swap_description' => $swapDescription,
            'changed_slot_keys' => $changedSlotKeys,
            'slots' => $slots,
        ];
    }

    /**
     * @param  list<array{key: string, label: string, abbreviation: string, grid_row: int, grid_column: int, player: ?Player, training_bar: ?int}>  $primarySlots
     * @param  array<string, Collection<int, Player>>  $pools
     * @param  list<array{key: string, label: string, abbreviation: string, grid_row: int, grid_column: int, position: PlayerPosition, pool_index: int}>  $slotDefinitions
     * @return list<array{
     *     kind: 'alternative',
     *     total_training_bar: int,
     *     swap_description: string,
     *     changed_slot_keys: list<string>,
     *     slots: list<array{
     *         key: string,
     *         label: string,
     *         abbreviation: string,
     *         grid_row: int,
     *         grid_column: int,
     *         player: ?Player,
     *         training_bar: ?int,
     *     }>
     * }>
     */
    private function buildAlternatives(array $primarySlots, array $pools, array $slotDefinitions): array
    {
        $primarySignature = $this->lineupSignature($primarySlots);
        $candidates = $this->generateCandidateLineups($pools, $slotDefinitions);

        $rankedCandidates = collect($candidates)
            ->filter(fn (array $slots): bool => $this->isComplete($slots))
            ->unique(fn (array $slots): string => $this->lineupSignature($slots))
            ->reject(fn (array $slots): bool => $this->lineupSignature($slots) === $primarySignature)
            ->filter(fn (array $slots): bool => $this->hasOnlyEligibleChanges($primarySlots, $slots))
            ->sortBy([
                fn (array $slots): int => $this->countSlotDifferences($primarySlots, $slots) >= self::MIN_ALTERNATIVE_CHANGES ? 0 : 1,
                fn (array $slots): int => -$this->countSlotDifferences($primarySlots, $slots),
                fn (array $slots): int => collect($slots)->sum('training_bar'),
                fn (array $slots): string => $this->lineupSignature($slots),
            ])
            ->values();

        return $this->selectAlternativeLineups($primarySlots, $rankedCandidates);
    }

    /**
     * @param  array<string, Collection<int, Player>>  $pools
     * @param  list<array{key: string, label: string, abbreviation: string, grid_row: int, grid_column: int, position: PlayerPosition, pool_index: int}>  $slotDefinitions
     * @return list<list<array{
     *     key: string,
     *     label: string,
     *     abbreviation: string,
     *     grid_row: int,
     *     grid_column: int,
     *     player: ?Player,
     *     training_bar: ?int,
     * }>>
     */
    private function generateCandidateLineups(array $pools, array $slotDefinitions): array
    {
        $definitionsByKey = collect($slotDefinitions)->keyBy('key');

        $oppositeChoices = ($pools[PlayerPosition::Opposite->value] ?? collect())
            ->take(self::CANDIDATES_PER_SINGLE_POSITION)
            ->values()
            ->all();
        $setterChoices = ($pools[PlayerPosition::Setter->value] ?? collect())
            ->take(self::CANDIDATES_PER_SINGLE_POSITION)
            ->values()
            ->all();
        $liberoChoices = ($pools[PlayerPosition::Libero->value] ?? collect())
            ->take(self::CANDIDATES_PER_SINGLE_POSITION)
            ->values()
            ->all();
        $middlePairs = $this->candidatePairs($pools[PlayerPosition::MiddleBlocker->value] ?? collect());
        $outsidePairs = $this->candidatePairs($pools[PlayerPosition::OutsideHitter->value] ?? collect());

        if ($oppositeChoices === [] || $setterChoices === [] || $liberoChoices === [] || $middlePairs === [] || $outsidePairs === []) {
            return [];
        }

        $lineups = [];
        $generated = 0;

        foreach ($oppositeChoices as $opposite) {
            foreach ($middlePairs as [$middleOne, $middleTwo]) {
                foreach ($outsidePairs as [$outsideOne, $outsideTwo]) {
                    foreach ($setterChoices as $setter) {
                        foreach ($liberoChoices as $libero) {
                            if ($generated >= self::MAX_LINEUP_CANDIDATES) {
                                break 5;
                            }

                            $generated++;
                            $lineups[] = [
                                $this->slotFromPlayer($definitionsByKey->get('opposite'), $opposite),
                                $this->slotFromPlayer($definitionsByKey->get('middle_1'), $middleOne),
                                $this->slotFromPlayer($definitionsByKey->get('outside_1'), $outsideOne),
                                $this->slotFromPlayer($definitionsByKey->get('outside_2'), $outsideTwo),
                                $this->slotFromPlayer($definitionsByKey->get('middle_2'), $middleTwo),
                                $this->slotFromPlayer($definitionsByKey->get('setter'), $setter),
                                $this->slotFromPlayer($definitionsByKey->get('libero'), $libero),
                            ];
                        }
                    }
                }
            }
        }

        return $lineups;
    }

    /**
     * @return list<array{0: Player, 1: Player}>
     */
    private function candidatePairs(Collection $pool): array
    {
        $players = $pool->take(self::CANDIDATES_PER_PAIR_POSITION)->values();
        $pairs = [];

        for ($firstIndex = 0; $firstIndex < $players->count(); $firstIndex++) {
            for ($secondIndex = $firstIndex + 1; $secondIndex < $players->count(); $secondIndex++) {
                $firstPlayer = $players[$firstIndex];
                $secondPlayer = $players[$secondIndex];

                if ($firstPlayer->training_bar <= $secondPlayer->training_bar) {
                    $pairs[] = [$firstPlayer, $secondPlayer];
                } else {
                    $pairs[] = [$secondPlayer, $firstPlayer];
                }
            }
        }

        return $pairs;
    }

    /**
     * @param  list<array{key: string, label: string, abbreviation: string, grid_row: int, grid_column: int, player: ?Player, training_bar: ?int}>  $primarySlots
     * @param  Collection<int, list<array{key: string, label: string, abbreviation: string, grid_row: int, grid_column: int, player: ?Player, training_bar: ?int}>>  $rankedCandidates
     * @return list<array{
     *     kind: 'alternative',
     *     total_training_bar: int,
     *     swap_description: string,
     *     changed_slot_keys: list<string>,
     *     slots: list<array{
     *         key: string,
     *         label: string,
     *         abbreviation: string,
     *         grid_row: int,
     *         grid_column: int,
     *         player: ?Player,
     *         training_bar: ?int,
     *     }>
     * }>
     */
    private function selectAlternativeLineups(array $primarySlots, Collection $rankedCandidates): array
    {
        $alternatives = [];
        $selectedSignatures = [];

        foreach ([self::MIN_ALTERNATIVE_CHANGES, 1] as $minimumChanges) {
            foreach ($rankedCandidates as $candidateSlots) {
                if (count($alternatives) >= self::ALTERNATIVE_COUNT) {
                    break 2;
                }

                $signature = $this->lineupSignature($candidateSlots);

                if (in_array($signature, $selectedSignatures, true)) {
                    continue;
                }

                $changedSlotKeys = $this->changedSlotKeys($primarySlots, $candidateSlots);

                if (count($changedSlotKeys) < $minimumChanges) {
                    continue;
                }

                $alternatives[] = $this->buildRecommendation(
                    'alternative',
                    $candidateSlots,
                    $this->buildChangesDescription($primarySlots, $candidateSlots, $changedSlotKeys),
                    $changedSlotKeys,
                );
                $selectedSignatures[] = $signature;
            }
        }

        return $alternatives;
    }

    /**
     * @param  list<array{key: string, label: string, abbreviation: string, grid_row: int, grid_column: int, player: ?Player, training_bar: ?int}>  $primarySlots
     * @param  list<array{key: string, label: string, abbreviation: string, grid_row: int, grid_column: int, player: ?Player, training_bar: ?int}>  $candidateSlots
     */
    private function hasOnlyEligibleChanges(array $primarySlots, array $candidateSlots): bool
    {
        $primaryByKey = collect($primarySlots)->keyBy('key');
        $hasChange = false;

        foreach ($candidateSlots as $slot) {
            $primarySlot = $primaryByKey->get($slot['key']);

            if ($primarySlot === null || $primarySlot['player']?->id === $slot['player']?->id) {
                continue;
            }

            $hasChange = true;

            if ($primarySlot['player'] === null || $slot['player'] === null) {
                return false;
            }

            if (! $this->shouldIncludeAlternativeSwap($primarySlot['player'], $slot['player'])) {
                return false;
            }
        }

        return $hasChange;
    }

    /**
     * @param  list<array{key: string, label: string, abbreviation: string, grid_row: int, grid_column: int, player: ?Player, training_bar: ?int}>  $primarySlots
     * @param  list<array{key: string, label: string, abbreviation: string, grid_row: int, grid_column: int, player: ?Player, training_bar: ?int}>  $candidateSlots
     * @return list<string>
     */
    private function changedSlotKeys(array $primarySlots, array $candidateSlots): array
    {
        $primaryByKey = collect($primarySlots)->keyBy('key');
        $changed = [];

        foreach ($candidateSlots as $slot) {
            $primarySlot = $primaryByKey->get($slot['key']);

            if ($primarySlot === null) {
                continue;
            }

            $primaryPlayer = $primarySlot['player'];
            $candidatePlayer = $slot['player'];

            if ($primaryPlayer === null || $candidatePlayer === null) {
                continue;
            }

            if ($primaryPlayer->id === $candidatePlayer->id) {
                continue;
            }

            if (! $this->shouldIncludeAlternativeSwap($primaryPlayer, $candidatePlayer)) {
                continue;
            }

            $changed[] = $slot['key'];
        }

        return $changed;
    }

    /**
     * @param  list<array{key: string, label: string, abbreviation: string, grid_row: int, grid_column: int, player: ?Player, training_bar: ?int}>  $primarySlots
     * @param  list<array{key: string, label: string, abbreviation: string, grid_row: int, grid_column: int, player: ?Player, training_bar: ?int}>  $candidateSlots
     */
    private function countSlotDifferences(array $primarySlots, array $candidateSlots): int
    {
        return count($this->changedSlotKeys($primarySlots, $candidateSlots));
    }

    /**
     * @param  list<string>  $changedSlotKeys
     */
    private function buildChangesDescription(array $primarySlots, array $candidateSlots, array $changedSlotKeys): string
    {
        $primaryByKey = collect($primarySlots)->keyBy('key');
        $candidateByKey = collect($candidateSlots)->keyBy('key');

        $changes = collect($changedSlotKeys)
            ->map(function (string $slotKey) use ($primaryByKey, $candidateByKey): string {
                $primarySlot = $primaryByKey->get($slotKey);
                $candidateSlot = $candidateByKey->get($slotKey);

                return sprintf(
                    '%s → %s (%s, %d%%)',
                    $primarySlot['player']->name,
                    $candidateSlot['player']->name,
                    $candidateSlot['label'],
                    $candidateSlot['training_bar'],
                );
            })
            ->all();

        return sprintf('%d zmiany: %s', count($changes), implode('; ', $changes));
    }

    private function shouldIncludeAlternativeSwap(Player $current, Player $candidate): bool
    {
        if ($candidate->training_bar < self::ALTERNATIVE_SWAP_MAX_TRAINING_BAR) {
            return true;
        }

        if ($current->training_bar < self::ALTERNATIVE_SWAP_MAX_TRAINING_BAR) {
            return false;
        }

        return abs($current->training_bar - $candidate->training_bar) <= self::ALTERNATIVE_SIMILAR_BAR_TOLERANCE;
    }

    /**
     * @param  list<array{key: string, player: ?Player}>  $slots
     */
    private function lineupSignature(array $slots): string
    {
        return collect($slots)
            ->sortBy('key')
            ->map(fn (array $slot): string => $slot['key'].':'.($slot['player']?->id ?? 'none'))
            ->implode('|');
    }
}
