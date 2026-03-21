<?php

namespace App;

use App\Enums\PlayerPosition;
use App\Models\Player;

final class TrainingOptimizerService
{
    public function __construct(
        public TrainingGainCalculator $trainingGainCalculator,
        public SubstitutionPlanGenerator $substitutionPlanGenerator,
    ) {}

    /**
     * @param  array<int, array{slot_number: int, position: PlayerPosition, players: array<int, Player>}>  $slotDefinitions
     * @return array<int, array{
     *     final_training_bar_sum: int,
     *     wasted_actions: int,
     *     substitutions_count: int,
     *     player_results: array<int, array{
     *         id: int,
     *         name: string,
     *         position: string,
     *         position_label: string,
     *         training_bar: int,
     *         starting_training_bar: int,
     *         played_actions: int,
     *         gained_training: int,
     *         final_training_bar: int,
     *         wasted_actions: int
     *     }>,
     *     plan: array{
     *         slots: array<int, array{
     *             slot_number: int,
     *             position: string,
     *             position_label: string,
     *             starter: array{id: int, name: string, position: string, training_bar: int},
     *             sets: array<int, array{
     *                 set_number: int,
     *                 starter_player: array{id: int, name: string, position: string, training_bar: int},
     *                 active_player: array{id: int, name: string, position: string, training_bar: int},
     *                 substitution_player: array{id: int, name: string, position: string, training_bar: int}|null,
     *                 activation_point: int|null,
     *                 description: string
     *             }>
     *         }>
     *     }
     * }>
     */
    public function optimize(array $slotDefinitions, MatchScenario $scenario, int $limit = 10): array
    {
        $plans = $this->substitutionPlanGenerator->generate($slotDefinitions, $scenario);

        $rankedPlans = array_map(
            fn (array $plan): array => $this->evaluatePlan($plan, $scenario),
            $plans,
        );

        usort($rankedPlans, function (array $left, array $right): int {
            if ($left['final_training_bar_sum'] !== $right['final_training_bar_sum']) {
                return $right['final_training_bar_sum'] <=> $left['final_training_bar_sum'];
            }

            if ($left['wasted_actions'] !== $right['wasted_actions']) {
                return $left['wasted_actions'] <=> $right['wasted_actions'];
            }

            return $left['substitutions_count'] <=> $right['substitutions_count'];
        });

        return array_slice($rankedPlans, 0, max(1, $limit));
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
     * } $plan
     * @return array{
     *     final_training_bar_sum: int,
     *     wasted_actions: int,
     *     substitutions_count: int,
     *     player_results: array<int, array{
     *         id: int,
     *         name: string,
     *         position: string,
     *         position_label: string,
     *         training_bar: int,
     *         starting_training_bar: int,
     *         played_actions: int,
     *         gained_training: int,
     *         final_training_bar: int,
     *         wasted_actions: int
     *     }>,
     *     plan: array{
     *         slots: array<int, array{
     *             slot_number: int,
     *             position: string,
     *             position_label: string,
     *             starter: array{id: int, name: string, position: string, training_bar: int},
     *             sets: array<int, array{
     *                 set_number: int,
     *                 starter_player: array{id: int, name: string, position: string, training_bar: int},
     *                 active_player: array{id: int, name: string, position: string, training_bar: int},
     *                 substitution_player: array{id: int, name: string, position: string, training_bar: int}|null,
     *                 activation_point: int|null,
     *                 description: string
     *             }>
     *         }>
     *     }
     * }
     */
    protected function evaluatePlan(array $plan, MatchScenario $scenario): array
    {
        $playedActionsByPlayer = [];
        $playerSummaries = [];
        $substitutionsCount = 0;

        foreach ($plan['slots'] as $slot) {
            foreach ($slot['sets'] as $setIndex => $set) {
                $setActions = $scenario->sets[$setIndex]['actions'] ?? 0;
                $starterPlayer = $set['starter_player'];
                $activePlayer = $set['active_player'];

                $playerSummaries[$starterPlayer['id']] = $starterPlayer;
                $playerSummaries[$activePlayer['id']] = $activePlayer;

                if ($set['substitution_player'] === null) {
                    $playedActionsByPlayer[$starterPlayer['id']] = ($playedActionsByPlayer[$starterPlayer['id']] ?? 0) + $setActions;

                    continue;
                }

                $substitutionsCount++;
                $playedActionsByPlayer[$starterPlayer['id']] = ($playedActionsByPlayer[$starterPlayer['id']] ?? 0) + min(1, $setActions);
                $playedActionsByPlayer[$activePlayer['id']] = ($playedActionsByPlayer[$activePlayer['id']] ?? 0) + max(0, $setActions - 1);
            }
        }

        $playerResults = collect($playerSummaries)
            ->sortBy('name')
            ->map(function (array $summary) use ($playedActionsByPlayer): array {
                $player = new Player;
                $player->fill([
                    'name' => $summary['name'],
                    'position' => $summary['position'],
                    'training_bar' => $summary['training_bar'],
                    'active' => true,
                ]);
                $player->id = $summary['id'];

                $calculation = $this->trainingGainCalculator->calculate(
                    $player,
                    $playedActionsByPlayer[$summary['id']] ?? 0,
                );

                return [
                    'id' => $summary['id'],
                    'name' => $summary['name'],
                    'position' => $summary['position'],
                    'position_label' => PlayerPosition::from($summary['position'])->label(),
                    'training_bar' => $summary['training_bar'],
                    ...$calculation,
                ];
            })
            ->values()
            ->all();

        return [
            'final_training_bar_sum' => array_sum(array_column($playerResults, 'final_training_bar')),
            'wasted_actions' => array_sum(array_column($playerResults, 'wasted_actions')),
            'substitutions_count' => $substitutionsCount,
            'player_results' => $playerResults,
            'plan' => $plan,
        ];
    }
}
