<?php

namespace App;

use App\Enums\PlayerPosition;
use App\Models\Player;
use Illuminate\Support\Facades\Log;

final class TrainingOptimizerService
{
    public function __construct(
        public TrainingGainCalculator $trainingGainCalculator,
        public SubstitutionPlanGenerator $substitutionPlanGenerator,
    ) {}

    /**
     * @param  array<int, array{slot_number: int, position: PlayerPosition, players: array<int, Player>}>  $slotDefinitions
     * @return array<int, array{
     *     total_gained_training: int,
     *     final_training_bar_sum: int,
     *     players_below_fairness_threshold: int,
     *     lowest_final_training_bar: int,
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
    public function optimize(
        array $slotDefinitions,
        MatchScenario $scenario,
        int $limit = 10,
        int $fairnessThreshold = 20,
    ): array {
        $fairnessThreshold = max(0, min(100, $fairnessThreshold));

        $this->debugOptimizer('optimizer.start', [
            'scenario' => [
                'label' => $scenario->label,
                'sets_count' => $scenario->setsCount(),
                'total_actions' => $scenario->totalActions(),
            ],
            'fairness_threshold' => $fairnessThreshold,
            'slot_definitions' => $this->summarizeSlotDefinitions($slotDefinitions),
        ]);

        $selectedCandidates = $this->selectCandidatesForOptimization($slotDefinitions);
        $useGreedyPlanner = $this->shouldUseGreedyPlanner($selectedCandidates);

        $this->debugOptimizer('optimizer.candidates.selected', [
            'planner' => $useGreedyPlanner ? 'greedy' : 'exhaustive',
            'selected_candidates' => $this->summarizeSlotDefinitions($selectedCandidates),
        ]);

        $plans = $useGreedyPlanner
            ? $this->substitutionPlanGenerator->generateGreedy($selectedCandidates, $scenario)
            : $this->substitutionPlanGenerator->generate($selectedCandidates, $scenario);

        $this->debugOptimizer('optimizer.plans.generated', [
            'planner' => $useGreedyPlanner ? 'greedy' : 'exhaustive',
            'count' => count($plans),
        ]);

        $rankedPlans = array_map(
            fn (array $plan): array => $this->evaluatePlan($plan, $scenario, $selectedCandidates, $fairnessThreshold),
            $plans,
        );

        usort($rankedPlans, fn (array $left, array $right): int => $this->compareRankedPlans($left, $right));

        $this->debugOptimizer('optimizer.rank.summary', [
            'fairness_threshold' => $fairnessThreshold,
            'top_plans' => array_map(
                function (array $plan, int $index): array {
                    return [
                        'rank' => $index + 1,
                        'total_gained_training' => $plan['total_gained_training'],
                        'final_training_bar_sum' => $plan['final_training_bar_sum'],
                        'players_below_fairness_threshold' => $plan['players_below_fairness_threshold'],
                        'lowest_final_training_bar' => collect($plan['player_results'])
                            ->min('final_training_bar'),
                        'wasted_actions' => $plan['wasted_actions'],
                        'substitutions_count' => $plan['substitutions_count'],
                        'players_with_actions' => collect($plan['player_results'])
                            ->where('played_actions', '>', 0)
                            ->pluck('name')
                            ->values()
                            ->all(),
                        'player_results' => collect($plan['player_results'])
                            ->map(fn (array $result): array => [
                                'name' => $result['name'],
                                'played_actions' => $result['played_actions'],
                                'gained_training' => $result['gained_training'],
                                'wasted_actions' => $result['wasted_actions'],
                            ])
                            ->values()
                            ->all(),
                    ];
                },
                array_slice($rankedPlans, 0, 5),
                array_keys(array_slice($rankedPlans, 0, 5)),
            ),
        ]);

        if (($rankedPlans[0]['substitutions_count'] ?? 0) === 0) {
            $this->debugOptimizer('optimizer.rank.best_has_no_substitutions', [
                'reason' => 'best ranked plan kept starters on court for all sets',
                'best_plan' => $rankedPlans[0] ?? null,
            ]);
        }

        return array_slice($rankedPlans, 0, max(1, $limit));
    }

    /**
     * @param  array<int, array{slot_number: int, position: PlayerPosition, reserve_limit?: int, players: array<int, Player>}>  $slotDefinitions
     * @return array<int, array{slot_number: int, position: PlayerPosition, players: array<int, Player>}>
     */
    protected function selectCandidatesForOptimization(array $slotDefinitions): array
    {
        return collect($slotDefinitions)
            ->groupBy(fn (array $slotDefinition): string => $slotDefinition['position']->value)
            ->flatMap(function ($group): array {
                $groupSlots = $group->values()->all();
                $slotCount = count($groupSlots);
                $reserveLimit = (int) ($groupSlots[0]['reserve_limit'] ?? 0);
                $candidateLimit = $slotCount + $reserveLimit;

                $players = collect($groupSlots)
                    ->flatMap(fn (array $slotDefinition): array => $slotDefinition['players'])
                    ->unique(fn (Player $player): int => $player->id)
                    ->sort(function (Player $left, Player $right): int {
                        if ($left->training_bar !== $right->training_bar) {
                            return $left->training_bar <=> $right->training_bar;
                        }

                        $nameComparison = strcmp($left->name, $right->name);

                        if ($nameComparison !== 0) {
                            return $nameComparison;
                        }

                        return $left->id <=> $right->id;
                    })
                    ->take($candidateLimit)
                    ->values()
                    ->all();

                $this->debugOptimizer('optimizer.candidates.group', [
                    'position' => $groupSlots[0]['position']->value ?? null,
                    'slot_count' => $slotCount,
                    'reserve_limit' => $reserveLimit,
                    'candidate_limit' => $candidateLimit,
                    'selected_candidates' => collect($players)
                        ->map(fn (Player $player): array => [
                            'id' => $player->id,
                            'name' => $player->name,
                            'training_bar' => $player->training_bar,
                        ])
                        ->values()
                        ->all(),
                ]);

                return array_map(fn (array $slotDefinition): array => [
                    ...$slotDefinition,
                    'players' => $players,
                ], $groupSlots);
            })
            ->sortBy('slot_number')
            ->values()
            ->all();
    }

    /**
     * @param  array<int, array{slot_number: int, position: PlayerPosition, players: array<int, Player>}>  $slotDefinitions
     */
    protected function shouldUseGreedyPlanner(array $slotDefinitions): bool
    {
        return collect($slotDefinitions)
            ->groupBy(fn (array $slotDefinition): string => $slotDefinition['position']->value)
            ->contains(fn ($group): bool => count($group->first()['players'] ?? []) > 5);
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
     *     total_gained_training: int,
     *     final_training_bar_sum: int,
     *     players_below_fairness_threshold: int,
     *     lowest_final_training_bar: int,
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
    protected function evaluatePlan(
        array $plan,
        MatchScenario $scenario,
        array $slotDefinitions,
        int $fairnessThreshold,
    ): array {
        $playedActionsByPlayer = [];
        $playerSummaries = collect($slotDefinitions)
            ->flatMap(fn (array $slotDefinition): array => $slotDefinition['players'])
            ->unique(fn (Player $player): int => $player->id)
            ->mapWithKeys(fn (Player $player): array => [$player->id => $this->playerSummary($player)])
            ->all();
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

        $playersBelowFairnessThreshold = collect($playerResults)
            ->filter(fn (array $result): bool => $result['final_training_bar'] < $fairnessThreshold)
            ->count();

        $lowestFinalTrainingBar = collect($playerResults)
            ->min('final_training_bar') ?? 0;

        return [
            'total_gained_training' => array_sum(array_column($playerResults, 'gained_training')),
            'final_training_bar_sum' => array_sum(array_column($playerResults, 'final_training_bar')),
            'players_below_fairness_threshold' => $playersBelowFairnessThreshold,
            'lowest_final_training_bar' => $lowestFinalTrainingBar,
            'fairness_threshold' => $fairnessThreshold,
            'wasted_actions' => array_sum(array_column($playerResults, 'wasted_actions')),
            'substitutions_count' => $substitutionsCount,
            'player_results' => $playerResults,
            'plan' => $plan,
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

    protected function summarizeSlotDefinitions(array $slotDefinitions): array
    {
        return collect($slotDefinitions)
            ->map(function (array $slotDefinition): array {
                return [
                    'slot_number' => $slotDefinition['slot_number'],
                    'position' => $slotDefinition['position']->value,
                    'reserve_limit' => $slotDefinition['reserve_limit'] ?? null,
                    'players_count' => count($slotDefinition['players']),
                    'players' => collect($slotDefinition['players'])
                        ->map(fn (Player $player): array => [
                            'id' => $player->id,
                            'name' => $player->name,
                            'training_bar' => $player->training_bar,
                        ])
                        ->values()
                        ->all(),
                ];
            })
            ->values()
            ->all();
    }

    protected function debugOptimizer(string $message, array $context = []): void
    {
        $application = app();

        if (! $application->bound('config') || ! (bool) $application['config']->get('app.debug')) {
            return;
        }

        Log::debug($message, $context);
    }

    /**
     * @param  array<int, array{final_training_bar: int}>  $leftPlayerResults
     * @param  array<int, array{final_training_bar: int}>  $rightPlayerResults
     */
    protected function compareFinalTrainingBarProfiles(array $leftPlayerResults, array $rightPlayerResults): int
    {
        $leftProfile = collect($leftPlayerResults)
            ->pluck('final_training_bar')
            ->sort()
            ->values()
            ->all();
        $rightProfile = collect($rightPlayerResults)
            ->pluck('final_training_bar')
            ->sort()
            ->values()
            ->all();
        $profileLength = max(count($leftProfile), count($rightProfile));

        for ($index = 0; $index < $profileLength; $index++) {
            $leftValue = $leftProfile[$index] ?? 0;
            $rightValue = $rightProfile[$index] ?? 0;

            if ($leftValue !== $rightValue) {
                return $rightValue <=> $leftValue;
            }
        }

        return 0;
    }

    /**
     * @param  array{
     *     total_gained_training: int,
     *     players_below_fairness_threshold: int,
     *     player_results: array<int, array{final_training_bar: int}>,
     *     wasted_actions: int,
     *     substitutions_count: int
     * }  $left
     * @param  array{
     *     total_gained_training: int,
     *     players_below_fairness_threshold: int,
     *     player_results: array<int, array{final_training_bar: int}>,
     *     wasted_actions: int,
     *     substitutions_count: int
     * }  $right
     */
    protected function compareRankedPlans(array $left, array $right): int
    {
        if ($left['total_gained_training'] !== $right['total_gained_training']) {
            return $right['total_gained_training'] <=> $left['total_gained_training'];
        }

        if ($left['players_below_fairness_threshold'] !== $right['players_below_fairness_threshold']) {
            return $left['players_below_fairness_threshold'] <=> $right['players_below_fairness_threshold'];
        }

        $fairnessComparison = $this->compareFinalTrainingBarProfiles($left['player_results'], $right['player_results']);

        if ($fairnessComparison !== 0) {
            return $fairnessComparison;
        }

        if ($left['wasted_actions'] !== $right['wasted_actions']) {
            return $left['wasted_actions'] <=> $right['wasted_actions'];
        }

        return $left['substitutions_count'] <=> $right['substitutions_count'];
    }
}
