<?php

namespace App;

use App\Enums\PlayerPosition;
use App\Models\Player;

final class TrainingPlanRefiner
{
    private const int MAX_PASSES = 8;

    public function __construct(private SubstitutionPlanGenerator $generator) {}

    /**
     * @param  array<int, array{slot_number: int, position: PlayerPosition, players: array<int, Player>}>  $slotDefinitions
     * @param  array<int, MatchScenario>  $scenarios
     * @return array{plan: array, gained_training_before: int, gained_training_after: int}
     */
    public function refine(array $plan, array $slotDefinitions, array $scenarios, bool $safeMode = false): array
    {
        $capacities = collect($slotDefinitions)->flatMap(fn (array $slot): array => $slot['players'])
            ->unique('id')->mapWithKeys(fn (Player $player): array => [$player->id => $player->maxTrainingGainPerMatch()])->all();
        $before = $this->score($plan, $scenarios, $capacities, $safeMode);
        $score = $before;

        for ($pass = 0; $pass < self::MAX_PASSES; $pass++) {
            $bestPlan = $plan;
            $bestScore = $score;

            foreach ($this->generator->generateLocalAlternatives($plan, $slotDefinitions) as $alternative) {
                $alternativeScore = $this->score($alternative, $scenarios, $capacities, $safeMode);

                if ($alternativeScore > $bestScore) {
                    $bestPlan = $alternative;
                    $bestScore = $alternativeScore;
                }
            }

            if ($bestScore === $score) {
                break;
            }

            $plan = $bestPlan;
            $score = $bestScore;
        }

        return ['plan' => $plan, 'gained_training_before' => $before, 'gained_training_after' => $score];
    }

    /**
     * @param  array<int, MatchScenario>  $scenarios
     * @param  array<int, int>  $capacities
     */
    private function score(array $plan, array $scenarios, array $capacities, bool $safeMode): int
    {
        $scores = [];

        foreach ($scenarios as $scenario) {
            $actions = [];

            foreach ($plan['slots'] as $slot) {
                foreach ($slot['sets'] as $index => $set) {
                    $setActions = $scenario->sets[$index]['actions'] ?? 0;
                    $starterId = $set['starter_player']['id'];
                    $activeId = $set['active_player']['id'];
                    $starterActions = $set['substitution_player'] === null ? $setActions : min(1, $setActions);
                    $actions[$starterId] = ($actions[$starterId] ?? 0) + $starterActions;

                    if ($set['substitution_player'] !== null) {
                        $actions[$activeId] = ($actions[$activeId] ?? 0) + max(0, $setActions - 1);
                    }
                }
            }

            $gain = 0;

            foreach ($actions as $id => $count) {
                $gain += min($count, $capacities[$id]);
            }

            $scores[] = $gain;
        }

        return $safeMode ? min($scores ?: [0]) : array_sum($scores);
    }
}
