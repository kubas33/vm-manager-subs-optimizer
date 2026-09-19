<?php

namespace App;

use App\Enums\PlayerPosition;
use App\Models\Player;

final class TrainingPlanDiagnostics
{
    /**
     * @param  array<int, array{slot_number: int, position: PlayerPosition, players: array<int, Player>}>  $slotDefinitions
     * @return array{players: array, sets: array, minimum_wasted_actions: int, excess_wasted_actions: int}
     */
    public function analyze(array $plan, array $slotDefinitions, MatchScenario $scenario): array
    {
        $candidates = collect($slotDefinitions)->flatMap(fn (array $slot): array => $slot['players'])->unique('id');
        $players = [];

        foreach ($candidates as $player) {
            $capacity = $player->maxTrainingGainPerMatch();
            $players[$player->id] = [
                'id' => $player->id, 'name' => $player->name, 'capacity' => $capacity,
                'played_actions' => 0, 'gained_training' => 0, 'wasted_actions' => 0,
                'limit_reached_set' => $capacity === 0 ? 0 : null,
            ];
        }

        $sets = [];

        foreach ($scenario->sets as $index => $scenarioSet) {
            $actions = [];

            foreach ($plan['slots'] as $slot) {
                $set = $slot['sets'][$index] ?? null;

                if ($set === null) {
                    continue;
                }

                $starterId = $set['starter_player']['id'];
                $activeId = $set['active_player']['id'];
                $starterActions = $set['substitution_player'] === null ? $scenarioSet['actions'] : min(1, $scenarioSet['actions']);
                $actions[$starterId] = ($actions[$starterId] ?? 0) + $starterActions;

                if ($set['substitution_player'] !== null) {
                    $actions[$activeId] = ($actions[$activeId] ?? 0) + max(0, $scenarioSet['actions'] - 1);
                }
            }

            $setPlayers = [];

            foreach ($actions as $id => $count) {
                $gain = min($count, max(0, $players[$id]['capacity'] - $players[$id]['gained_training']));
                $players[$id]['played_actions'] += $count;
                $players[$id]['gained_training'] += $gain;
                $players[$id]['wasted_actions'] += $count - $gain;

                if ($players[$id]['limit_reached_set'] === null && $players[$id]['gained_training'] === $players[$id]['capacity']) {
                    $players[$id]['limit_reached_set'] = $index + 1;
                }

                $setPlayers[] = ['id' => $id, 'name' => $players[$id]['name'], 'played_actions' => $count, 'gained_training' => $gain, 'wasted_actions' => $count - $gain];
            }

            $sets[$index + 1] = ['players' => $setPlayers, 'gained_training' => array_sum(array_column($setPlayers, 'gained_training')), 'wasted_actions' => array_sum(array_column($setPlayers, 'wasted_actions'))];
        }

        $minimum = 0;

        foreach (collect($slotDefinitions)->groupBy(fn (array $slot): string => $slot['position']->value) as $group) {
            $capacity = collect($group)->flatMap(fn (array $slot): array => $slot['players'])->unique('id')
                ->sum(fn (Player $player): int => $player->maxTrainingGainPerMatch());
            $minimum += max(0, $scenario->totalActions() * count($group) - $capacity);
        }

        return [
            'players' => $players, 'sets' => $sets, 'minimum_wasted_actions' => $minimum,
            'excess_wasted_actions' => max(0, array_sum(array_column($players, 'wasted_actions')) - $minimum),
        ];
    }
}
