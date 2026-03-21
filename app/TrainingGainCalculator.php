<?php

namespace App;

use App\Models\Player;

final class TrainingGainCalculator
{
    /**
     * @return array{
     *     starting_training_bar: int,
     *     played_actions: int,
     *     gained_training: int,
     *     final_training_bar: int,
     *     wasted_actions: int
     * }
     */
    public function calculate(Player $player, int $playedActions): array
    {
        $normalizedPlayedActions = max(0, $playedActions);
        $gainedTraining = min($normalizedPlayedActions, $player->maxTrainingGainPerMatch());

        return [
            'starting_training_bar' => $player->training_bar,
            'played_actions' => $normalizedPlayedActions,
            'gained_training' => $gainedTraining,
            'final_training_bar' => $player->projectedTrainingBar($normalizedPlayedActions),
            'wasted_actions' => $player->wastedTrainingActions($normalizedPlayedActions),
        ];
    }

    /**
     * @return array{
     *     starting_training_bar: int,
     *     played_actions: int,
     *     gained_training: int,
     *     final_training_bar: int,
     *     wasted_actions: int
     * }
     */
    public function calculateForScenario(Player $player, MatchScenario $scenario): array
    {
        return $this->calculate($player, $scenario->totalActions());
    }
}
