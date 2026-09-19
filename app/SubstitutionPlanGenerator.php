<?php

namespace App;

use App\Enums\PlayerPosition;
use App\Models\Player;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

final class SubstitutionPlanGenerator
{
    /**
     * @param  array<int, array{slot_number: int, position: PlayerPosition, players: array<int, Player>}>  $slotDefinitions
     * @return array<int, array{
     *     slots: array<int, array{
     *         slot_number: int,
     *         position: string,
     *         position_label: string,
     *         starter: array{id: int, name: string, position: string, training_bar: int},
     *         sets: array<int, array{
     *             set_number: int,
     *             starter_player: array{id: int, name: string, position: string, training_bar: int},
     *             active_player: array{id: int, name: string, position: string, training_bar: int},
     *             substitution_player: array{id: int, name: string, position: string, training_bar: int}|null,
     *             activation_point: int|null,
     *             description: string
     *         }>
     *     }>
     * }
     */
    public function generate(array $slotDefinitions, MatchScenario $scenario): array
    {
        if (count($slotDefinitions) < 1 || count($slotDefinitions) > 5) {
            throw new InvalidArgumentException('Generator oczekuje od jednego do pięciu analizowanych slotów.');
        }

        $normalizedSlots = collect($slotDefinitions)
            ->sortBy('slot_number')
            ->values()
            ->all();

        $positionGroups = collect($normalizedSlots)
            ->groupBy(fn (array $slot): string => $slot['position']->value)
            ->map(fn ($group): array => $group->values()->all())
            ->values()
            ->all();

        $groupVariants = array_map(
            fn (array $group): array => $this->generatePositionGroupVariants($group, $scenario),
            $positionGroups,
        );

        if (collect($groupVariants)->contains(fn (array $variants): bool => $variants === [])) {
            return [];
        }

        return array_map(function (array $variant): array {
            $slots = collect($variant)
                ->flatMap(fn (array $groupVariant): array => $groupVariant['slots'])
                ->sortBy('slot_number')
                ->values()
                ->all();

            return [
                'slots' => $slots,
            ];
        }, $this->cartesianProduct($groupVariants));
    }

    /**
     * @param  array<int, array{slot_number: int, position: PlayerPosition, players: array<int, Player>}>  $slotDefinitions
     * @return array<int, array{
     *     slots: array<int, array{
     *         slot_number: int,
     *         position: string,
     *         position_label: string,
     *         starter: array{id: int, name: string, position: string, training_bar: int},
     *         sets: array<int, array{
     *             set_number: int,
     *             starter_player: array{id: int, name: string, position: string, training_bar: int},
     *             active_player: array{id: int, name: string, position: string, training_bar: int},
     *             substitution_player: array{id: int, name: string, position: string, training_bar: int}|null,
     *             activation_point: int|null,
     *             description: string
     *         }>
     *     }>
     * }>
     */
    public function generateGreedy(array $slotDefinitions, MatchScenario $scenario): array
    {
        if (count($slotDefinitions) < 1 || count($slotDefinitions) > 5) {
            throw new InvalidArgumentException('Generator oczekuje od jednego do pięciu analizowanych slotów.');
        }

        $this->debugGenerator('optimizer.greedy.start', [
            'scenario' => [
                'label' => $scenario->label,
                'sets_count' => $scenario->setsCount(),
                'total_actions' => $scenario->totalActions(),
            ],
            'slot_definitions' => collect($slotDefinitions)
                ->map(function (array $slotDefinition): array {
                    return [
                        'slot_number' => $slotDefinition['slot_number'],
                        'position' => $slotDefinition['position']->value,
                        'players_count' => count($slotDefinition['players']),
                        'players' => collect($slotDefinition['players'])
                            ->map(fn (Player $player): array => $this->playerSummary($player))
                            ->values()
                            ->all(),
                    ];
                })
                ->values()
                ->all(),
        ]);

        $normalizedSlots = collect($slotDefinitions)
            ->sortBy('slot_number')
            ->values()
            ->all();

        $positionGroups = collect($normalizedSlots)
            ->groupBy(fn (array $slot): string => $slot['position']->value)
            ->map(fn ($group): array => $group->values()->all())
            ->values()
            ->all();

        $groupVariants = array_map(
            fn (array $group): array => $this->generateGreedyPositionGroupVariants($group, $scenario),
            $positionGroups,
        );

        if (collect($groupVariants)->contains(fn (array $variants): bool => $variants === [])) {
            return [];
        }

        return array_map(function (array $variantCombination): array {
            $slots = collect($variantCombination)
                ->flatMap(fn (array $groupVariant): array => $groupVariant['slots'])
                ->sortBy('slot_number')
                ->values()
                ->all();

            return [
                'slots' => $slots,
            ];
        }, $this->cartesianProduct($groupVariants));
    }

    /**
     * @param  array<int, array{slot_number: int, position: PlayerPosition, players: array<int, Player>}>  $group
     * @return array<int, array{
     *     slots: array<int, array{
     *         slot_number: int,
     *         position: string,
     *         position_label: string,
     *         starter: array{id: int, name: string, position: string, training_bar: int},
     *         sets: array<int, array{
     *             set_number: int,
     *             starter_player: array{id: int, name: string, position: string, training_bar: int},
     *             active_player: array{id: int, name: string, position: string, training_bar: int},
     *             substitution_player: array{id: int, name: string, position: string, training_bar: int}|null,
     *             activation_point: int|null,
     *             description: string
     *         }>
     *     }>
     * }>
     */
    protected function generatePositionGroupVariants(array $group, MatchScenario $scenario): array
    {
        $requiredSlots = count($group);
        $candidates = $this->uniquePlayers(
            collect($group)->flatMap(fn (array $slot): array => $slot['players'])->all(),
        );

        if (count($candidates) < $requiredSlots) {
            return [];
        }

        $slotTemplates = array_values($group);
        $startingLineups = $this->orderedSelections($candidates, $requiredSlots);
        $variants = [];
        $seenSignatures = [];
        $uniqueVariants = [];

        foreach ($startingLineups as $startingPlayers) {
            $perSetOptions = $this->generateSetAssignments($startingPlayers, $candidates);
            $setPlans = $this->repeatChoices($perSetOptions, $scenario->setsCount());

            foreach ($setPlans as $setPlan) {
                $slots = [];

                foreach ($slotTemplates as $slotIndex => $slotTemplate) {
                    $starter = $startingPlayers[$slotIndex];
                    $sets = [];

                    foreach ($setPlan as $setNumber => $setAssignments) {
                        $assignment = $setAssignments[$slotIndex];
                        $sets[] = $this->buildSetEntry(
                            starter: $starter,
                            activePlayer: $assignment,
                            setNumber: $setNumber + 1,
                            slotNumber: $slotTemplate['slot_number'],
                            positionLabel: $slotTemplate['position']->label(),
                        );
                    }

                    $slots[] = [
                        'slot_number' => $slotTemplate['slot_number'],
                        'position' => $slotTemplate['position']->value,
                        'position_label' => $slotTemplate['position']->label(),
                        'starter' => $this->playerSummary($starter),
                        'sets' => $sets,
                    ];
                }

                $variants[] = [
                    'slots' => $slots,
                ];
            }
        }

        foreach ($variants as $variant) {
            $signature = $this->buildVariantSignature($variant);

            if (isset($seenSignatures[$signature])) {
                continue;
            }

            $seenSignatures[$signature] = true;
            $uniqueVariants[] = $variant;
        }

        return $uniqueVariants;
    }

    /**
     * @param  array<int, array{slot_number: int, position: PlayerPosition, players: array<int, Player>}>  $group
     * @return array<int, array{
     *     slots: array<int, array{
     *         slot_number: int,
     *         position: string,
     *         position_label: string,
     *         starter: array{id: int, name: string, position: string, training_bar: int},
     *         sets: array<int, array{
     *             set_number: int,
     *             starter_player: array{id: int, name: string, position: string, training_bar: int},
     *             active_player: array{id: int, name: string, position: string, training_bar: int},
     *             substitution_player: array{id: int, name: string, position: string, training_bar: int}|null,
     *             activation_point: int|null,
     *             description: string
     *         }>
     *     }>
     * }>
     */
    protected function generateGreedyPositionGroupVariants(array $group, MatchScenario $scenario): array
    {
        $requiredSlots = count($group);
        $candidates = $this->uniquePlayers(
            collect($group)->flatMap(fn (array $slot): array => $slot['players'])->all(),
        );

        if (count($candidates) < $requiredSlots) {
            return [];
        }

        usort($candidates, function (Player $left, Player $right): int {
            if ($left->training_bar !== $right->training_bar) {
                return $left->training_bar <=> $right->training_bar;
            }

            $nameComparison = strcmp($left->name, $right->name);

            if ($nameComparison !== 0) {
                return $nameComparison;
            }

            return $left->id <=> $right->id;
        });

        $this->debugGenerator('optimizer.greedy.group.start', [
            'position' => $group[0]['position']->value ?? null,
            'slot_count' => $requiredSlots,
            'candidate_count' => count($candidates),
            'starter_pool_size' => min(count($candidates), $requiredSlots + 1),
        ]);

        $starterPool = array_slice($candidates, 0, min(count($candidates), $requiredSlots + 1));
        $startingLineups = $this->orderedSelections($starterPool, $requiredSlots);
        $variants = [];
        $seenSignatures = [];

        foreach ($startingLineups as $starters) {
            $variant = $this->buildGreedyPositionGroupVariant($group, $scenario, $starters, $candidates);

            if ($variant === []) {
                continue;
            }

            $signature = $this->buildVariantSignature($variant);

            if (isset($seenSignatures[$signature])) {
                continue;
            }

            $seenSignatures[$signature] = true;
            $variants[] = $variant;
        }

        $this->debugGenerator('optimizer.greedy.group.variants', [
            'position' => $group[0]['position']->value ?? null,
            'starter_lineups' => count($startingLineups),
            'variant_count' => count($variants),
        ]);

        return $variants;
    }

    /**
     * @param  array<int, array{slot_number: int, position: PlayerPosition, players: array<int, Player>}>  $group
     * @param  array<int, Player>  $starters
     * @param  array<int, Player>  $candidates
     * @return array{
     *     slots: array<int, array{
     *         slot_number: int,
     *         position: string,
     *         position_label: string,
     *         starter: array{id: int, name: string, position: string, training_bar: int},
     *         sets: array<int, array{
     *             set_number: int,
     *             starter_player: array{id: int, name: string, position: string, training_bar: int},
     *             active_player: array{id: int, name: string, position: string, training_bar: int},
     *             substitution_player: array{id: int, name: string, position: string, training_bar: int}|null,
     *             activation_point: int|null,
     *             description: string
     *         }>
     *     }>
     * }
     */
    protected function buildGreedyPositionGroupVariant(
        array $group,
        MatchScenario $scenario,
        array $starters,
        array $candidates,
    ): array {
        $slotTemplates = array_values($group);
        $starterIds = array_map(fn (Player $player): int => $player->id, $starters);
        $benchPlayers = array_values(array_filter(
            $candidates,
            fn (Player $player): bool => ! in_array($player->id, $starterIds, true),
        ));

        $remainingCapacity = [];

        foreach ($candidates as $candidate) {
            $remainingCapacity[$candidate->id] = $candidate->maxTrainingGainPerMatch();
        }

        $this->debugGenerator('optimizer.greedy.group.variant.start', [
            'position' => $slotTemplates[0]['position']->value ?? null,
            'starters' => array_map(
                fn (Player $player): array => $this->playerSummary($player),
                $starters,
            ),
            'bench_players' => array_map(
                fn (Player $player): array => $this->playerSummary($player),
                $benchPlayers,
            ),
        ]);

        $slots = array_map(
            fn (array $slotTemplate, int $slotIndex): array => [
                'slot_number' => $slotTemplate['slot_number'],
                'position' => $slotTemplate['position']->value,
                'position_label' => $slotTemplate['position']->label(),
                'starter' => $this->playerSummary($starters[$slotIndex]),
                'sets' => [],
            ],
            $slotTemplates,
            array_keys($slotTemplates),
        );

        $setAssignments = $this->generateSetAssignments($starters, $candidates);

        foreach ($scenario->sets as $setIndex => $scenarioSet) {
            $setActions = $scenarioSet['actions'] ?? 0;
            $bestAssignment = $starters;
            $bestGain = -1;
            $fewestSubstitutions = PHP_INT_MAX;

            foreach ($setAssignments as $assignment) {
                $totalGain = 0;
                $substitutions = 0;

                foreach ($assignment as $slotIndex => $activePlayer) {
                    $starter = $starters[$slotIndex];
                    $starterRemaining = $remainingCapacity[$starter->id];

                    if ($activePlayer->id === $starter->id) {
                        $totalGain += min($setActions, $starterRemaining);
                    } else {
                        $totalGain += min(1, $setActions, $starterRemaining)
                            + min(max(0, $setActions - 1), $remainingCapacity[$activePlayer->id]);
                        $substitutions++;
                    }
                }

                if ($totalGain > $bestGain || ($totalGain === $bestGain && $substitutions < $fewestSubstitutions)) {
                    $bestAssignment = $assignment;
                    $bestGain = $totalGain;
                    $fewestSubstitutions = $substitutions;
                }
            }

            $this->debugGenerator('optimizer.greedy.group.choice', [
                'set_number' => $setIndex + 1,
                'set_actions' => $setActions,
                'position' => $slotTemplates[0]['position']->value,
                'selected_players' => array_map(
                    fn (Player $player): array => $this->playerSummary($player),
                    $bestAssignment,
                ),
                'selected_gain' => $bestGain,
                'remaining_capacity_before_update' => $remainingCapacity,
            ]);

            foreach ($slotTemplates as $slotIndex => $slotTemplate) {
                $starter = $starters[$slotIndex];
                $activePlayer = $bestAssignment[$slotIndex];
                $starterActions = $activePlayer->id === $starter->id ? $setActions : min(1, $setActions);
                $remainingCapacity[$starter->id] = max(0, $remainingCapacity[$starter->id] - $starterActions);

                if ($activePlayer->id !== $starter->id) {
                    $remainingCapacity[$activePlayer->id] = max(0, $remainingCapacity[$activePlayer->id] - max(0, $setActions - 1));
                }

                $slots[$slotIndex]['sets'][] = $this->buildSetEntry(
                    starter: $starter,
                    activePlayer: $activePlayer,
                    setNumber: $setIndex + 1,
                    slotNumber: $slotTemplate['slot_number'],
                    positionLabel: $slotTemplate['position']->label(),
                );
            }
        }

        return [
            'slots' => $slots,
        ];
    }

    protected function debugGenerator(string $message, array $context = []): void
    {
        $application = app();

        if (! $application->bound('config') || ! (bool) $application['config']->get('app.debug')) {
            return;
        }

        Log::debug($message, $context);
    }

    /**
     * @param  array{
     *     slots: array<int, array{
     *         slot_number: int,
     *         position: string,
     *         position_label: string,
     *         starter: array{id: int, name: string, position: string, training_bar: int},
     *         sets: array<int, array{
     *             set_number: int,
     *             starter_player: array{id: int, name: string, position: string, training_bar: int},
     *             active_player: array{id: int, name: string, position: string, training_bar: int},
     *             substitution_player: array{id: int, name: string, position: string, training_bar: int}|null,
     *             activation_point: int|null,
     *             description: string
     *         }>
     *     }>
     * }  $variant
     */
    protected function buildVariantSignature(array $variant): string
    {
        $normalizedSlots = collect($variant['slots'])
            ->groupBy('position')
            ->map(function ($slots, string $position): array {
                $slotCount = count($slots);
                $firstSlot = $slots[0] ?? null;
                $setCount = is_array($firstSlot) ? count($firstSlot['sets'] ?? []) : 0;
                $setSignatures = [];

                for ($setIndex = 0; $setIndex < $setCount; $setIndex++) {
                    $activePlayerIds = collect($slots)
                        ->map(fn (array $slot): int => $slot['sets'][$setIndex]['active_player']['id'])
                        ->sort()
                        ->values()
                        ->all();

                    $setSignatures[] = $activePlayerIds;
                }

                return [
                    'position' => $position,
                    'slot_count' => $slotCount,
                    'sets' => $setSignatures,
                ];
            })
            ->sortBy('position')
            ->values()
            ->all();

        return (string) json_encode($normalizedSlots);
    }

    /**
     * @param  array<int, array{slot_number: int, position: PlayerPosition, players: array<int, Player>}>  $slotDefinitions
     * @return iterable<array{slots: array}>
     */
    public function generateLocalAlternatives(array $plan, array $slotDefinitions): iterable
    {
        $players = collect($slotDefinitions)->flatMap(fn (array $slot): array => $slot['players'])
            ->unique('id')->keyBy('id')->all();
        $groups = [];

        foreach ($plan['slots'] as $slotIndex => $slot) {
            $groups[$slot['position']][] = $slotIndex;
        }

        foreach ($groups as $position => $slotIndices) {
            $starters = array_map(fn (int $index): Player => $players[$plan['slots'][$index]['starter']['id']], $slotIndices);
            $candidates = array_values(array_filter($players, fn (Player $player): bool => $player->position->value === $position && ! $player->isInjured));
            $assignments = $this->generateSetAssignments($starters, $candidates);
            $setCount = count($plan['slots'][$slotIndices[0]]['sets']);

            for ($setIndex = 0; $setIndex < $setCount; $setIndex++) {
                foreach ($assignments as $assignment) {
                    $alternative = $plan;

                    foreach ($slotIndices as $groupIndex => $slotIndex) {
                        $slot = $plan['slots'][$slotIndex];
                        $alternative['slots'][$slotIndex]['sets'][$setIndex] = $this->buildSetEntry(
                            $starters[$groupIndex], $assignment[$groupIndex], $setIndex + 1,
                            $slot['slot_number'], $slot['position_label'],
                        );
                    }

                    yield $alternative;
                }

                for ($otherSet = $setIndex + 1; $otherSet < $setCount; $otherSet++) {
                    $alternative = $plan;

                    foreach ($slotIndices as $groupIndex => $slotIndex) {
                        $slot = $plan['slots'][$slotIndex];

                        foreach ([$setIndex => $otherSet, $otherSet => $setIndex] as $target => $source) {
                            $alternative['slots'][$slotIndex]['sets'][$target] = $this->buildSetEntry(
                                $starters[$groupIndex], $players[$slot['sets'][$source]['active_player']['id']],
                                $target + 1, $slot['slot_number'], $slot['position_label'],
                            );
                        }
                    }

                    yield $alternative;
                }
            }
        }
    }

    /**
     * @param  array<int, Player>  $starters
     * @param  array<int, Player>  $candidates
     * @return array<int, array<int, Player>>
     */
    protected function generateSetAssignments(array $starters, array $candidates): array
    {
        $starterIds = array_map(fn (Player $player): int => $player->id, $starters);
        $benchPlayers = array_values(array_filter(
            $candidates,
            fn (Player $player): bool => ! in_array($player->id, $starterIds, true),
        ));

        return $this->generateSlotAssignments($starters, $benchPlayers, 0, [], []);
    }

    /**
     * @param  array<int, Player>  $starters
     * @param  array<int, Player>  $benchPlayers
     * @param  array<int, Player>  $currentAssignments
     * @param  array<int, int>  $usedBenchIds
     * @return array<int, array<int, Player>>
     */
    protected function generateSlotAssignments(
        array $starters,
        array $benchPlayers,
        int $slotIndex,
        array $currentAssignments,
        array $usedBenchIds,
    ): array {
        if ($slotIndex === count($starters)) {
            return [$currentAssignments];
        }

        $variants = [];
        $starter = $starters[$slotIndex];

        $stayAssignments = $currentAssignments;
        $stayAssignments[] = $starter;
        $variants = array_merge(
            $variants,
            $this->generateSlotAssignments($starters, $benchPlayers, $slotIndex + 1, $stayAssignments, $usedBenchIds),
        );

        foreach ($benchPlayers as $benchPlayer) {
            if (in_array($benchPlayer->id, $usedBenchIds, true)) {
                continue;
            }

            $subAssignments = $currentAssignments;
            $subAssignments[] = $benchPlayer;
            $variants = array_merge(
                $variants,
                $this->generateSlotAssignments(
                    $starters,
                    $benchPlayers,
                    $slotIndex + 1,
                    $subAssignments,
                    [...$usedBenchIds, $benchPlayer->id],
                ),
            );
        }

        return $variants;
    }

    /**
     * @param  array<int, Player>  $players
     * @return array<int, array<int, Player>>
     */
    protected function orderedSelections(array $players, int $length): array
    {
        if ($length === 0) {
            return [[]];
        }

        $selections = [];

        foreach ($players as $index => $player) {
            $remainingPlayers = $players;
            unset($remainingPlayers[$index]);

            foreach ($this->orderedSelections(array_values($remainingPlayers), $length - 1) as $selection) {
                $selections[] = [$player, ...$selection];
            }
        }

        return $selections;
    }

    /**
     * @param  array<int, array<int, mixed>>  $choiceSets
     * @return array<int, array<int, mixed>>
     */
    protected function cartesianProduct(array $choiceSets): array
    {
        $products = [[]];

        foreach ($choiceSets as $choiceSet) {
            $nextProducts = [];

            foreach ($products as $product) {
                foreach ($choiceSet as $choice) {
                    $nextProducts[] = [...$product, $choice];
                }
            }

            $products = $nextProducts;
        }

        return $products;
    }

    /**
     * @param  array<int, mixed>  $choices
     * @return array<int, array<int, mixed>>
     */
    protected function repeatChoices(array $choices, int $times): array
    {
        if ($times === 0) {
            return [[]];
        }

        $plans = [[]];

        for ($index = 0; $index < $times; $index++) {
            $nextPlans = [];

            foreach ($plans as $plan) {
                foreach ($choices as $choice) {
                    $nextPlans[] = [...$plan, $choice];
                }
            }

            $plans = $nextPlans;
        }

        return $plans;
    }

    /**
     * @param  array<int, Player>  $players
     * @return array<int, Player>
     */
    protected function uniquePlayers(array $players): array
    {
        return collect($players)
            ->reject(fn (Player $player): bool => $player->isInjured)
            ->unique(fn (Player $player): int => $player->id)
            ->values()
            ->all();
    }

    /**
     * @return array{
     *     set_number: int,
     *     starter_player: array{id: int, name: string, position: string, training_bar: int},
     *     active_player: array{id: int, name: string, position: string, training_bar: int},
     *     substitution_player: array{id: int, name: string, position: string, training_bar: int}|null,
     *     activation_point: int|null,
     *     description: string
     * }
     */
    protected function buildSetEntry(
        Player $starter,
        Player $activePlayer,
        int $setNumber,
        int $slotNumber,
        string $positionLabel,
    ): array {
        $substitutionPlayer = $activePlayer->id === $starter->id ? null : $activePlayer;

        return [
            'set_number' => $setNumber,
            'starter_player' => $this->playerSummary($starter),
            'active_player' => $this->playerSummary($activePlayer),
            'substitution_player' => $substitutionPlayer ? $this->playerSummary($substitutionPlayer) : null,
            'activation_point' => $substitutionPlayer ? 1 : null,
            'description' => $substitutionPlayer
                ? 'Slot '.$slotNumber.' ('.$positionLabel.'): '.$starter->name.' start, Set '.$setNumber.' od 1 punktu -> '.$activePlayer->name
                : 'Slot '.$slotNumber.' ('.$positionLabel.'): '.$starter->name.' bez zmiany w secie '.$setNumber,
        ];
    }

    /**
     * @return array{id: int, name: string, position: string, training_bar: int}
     */
    protected function playerSummary(Player $player): array
    {
        return [
            'id' => $player->id,
            'name' => $player->name,
            'position' => $player->position->value,
            'training_bar' => $player->training_bar,
        ];
    }
}
