<?php

namespace App;

use App\Enums\PlayerPosition;
use App\Models\Player;
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
        if (count($slotDefinitions) !== 2) {
            throw new InvalidArgumentException('Generator oczekuje dokładnie dwóch analizowanych slotów.');
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

        return $variants;
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
