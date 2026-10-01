<?php

use App\Enums\PlayerPosition;
use App\LineupRecommendationService;
use App\MatchScenario;
use App\Models\Player;
use App\SubstitutionPlanGenerator;
use App\TrainingGainCalculator;
use App\TrainingOptimizerService;
use App\VariantLineupComposer;
use App\VmSubstitutionService;
use App\VmTacticsService;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Wynik optymalizacji')] class extends Component
{
    #[Locked] public array $optimizerInput = [];
    public string $selectedScenarioKey = '';
    public string $selectedVariantKey = '';
    public string $tacticsMatchType = 'League';
    public bool $confirmingVariantApplication = false;
    public bool $confirmingChangesDeletion = false;
    public ?string $pendingScenarioKey = null;
    public ?string $pendingVariantKey = null;
    public string $tacticsStatus = '';
    public string $changesDeletionStatus = '';
    public bool $editingVariant = false;
    public array $manualAssignments = [];
    public array $savedManualVariants = [];
    public string $compareVariantKey = '';
    #[Locked] public string $manualOptimizationError = '';

    public function startManualEdit(): void
    {
        $this->manualOptimizationError = '';
        $savedAssignments = $this->savedManualVariants[$this->selectedScenarioKey.'|'.$this->selectedVariantKey] ?? [];
        $variant = $this->activeVariant;
        $this->manualAssignments = is_array($variant)
            ? $this->initializeManualAssignments($variant, is_array($savedAssignments) ? $savedAssignments : [])
            : [];
        $this->editingVariant = true;
    }

    public function cancelManualEdit(): void
    {
        $this->editingVariant = false;
        $this->manualOptimizationError = '';
        $this->manualAssignments = [];
    }

    public function updatedManualAssignments(mixed $value, string $key): void
    {
        if (! $this->editingVariant) {
            return;
        }
        if (preg_match('/^\d+-\d+$/', $key) === 1) {
            $this->manualOptimizationError = '';
            unset($this->manualPreview);
            $selectedIds = array_map(fn (mixed $assignment): ?int => $this->manualPlayerId($assignment), $this->additionalBenchAssignments($this->manualAssignments));
            foreach (collect($this->manualPreview['bench'] ?? [])->filter() as $player) {
                if (in_array($player->id, $selectedIds, true)) {
                    continue;
                }
                foreach (range(1, 5) as $index) {
                    if (($this->manualAssignments['bench-'.$index] ?? '') === '') {
                        $this->manualAssignments['bench-'.$index] = (string) $player->id;
                        $selectedIds[] = $player->id;
                        break;
                    }
                }
            }
            unset($this->manualPreview);

            return;
        }
        if (! str_starts_with($key, 'starter-') && ! str_starts_with($key, 'bench-')) {
            return;
        }

        $this->manualOptimizationError = '';
        if (str_starts_with($key, 'starter-')) {
            $starterId = $this->manualPlayerId($value);
            foreach ($this->manualAssignments as $assignmentKey => $assignment) {
                if (str_starts_with($assignmentKey, 'bench-') && $starterId !== null && $this->manualPlayerId($assignment) === $starterId) {
                    $this->manualAssignments[$assignmentKey] = '';
                }
            }
        }
        $this->resetValidation('manual');
        unset($this->manualPreview);
        $variant = $this->manualPreview;
        if (! is_array($variant)) {
            return;
        }
        $scenario = collect($this->scenarioModels)->first(fn (MatchScenario $item): bool => hash('sha256', preg_replace('/\s+/', '', $item->input)) === $this->selectedScenarioKey);
        if (! $scenario instanceof MatchScenario) {
            return;
        }

        $selectionPlan = $variant['plan'];
        foreach ($selectionPlan['slots'] as &$slot) {
            foreach ($slot['sets'] as &$set) {
                $set['substitution_player'] = null;
            }
            unset($set);
        }
        unset($slot);
        $starterChoices = collect($this->manualAssignments)->filter(fn (mixed $assignment, string $assignmentKey): bool => str_starts_with($assignmentKey, 'starter-'))->all();
        $selection = (new VariantLineupComposer)->compose(
            $this->lineupRecommendations['recommendations'][0] ?? [],
            $selectionPlan,
            $starterChoices,
            $this->additionalBenchAssignments($this->manualAssignments),
        );
        if (! $selection['is_sendable']) {
            $this->manualOptimizationError = collect($selection['send_blockers'])->pluck('message')->implode(' ');
            unset($this->manualPreview);

            return;
        }
        $courtKeys = $this->planSlotCourtKeys($variant['plan']);
        $bench = collect($selection['bench'])->filter();
        $definitions = [];
        $constraints = $this->optimizerInput['player_constraints'] ?? [];
        foreach ($variant['plan']['slots'] as $slot) {
            $starter = $selection['lineup'][$courtKeys[$slot['slot_number']]]['player'];
            $reserves = $bench->filter(fn (Player $player): bool => $player->position === $starter->position);
            $samePositionStarters = collect($variant['plan']['slots'])
                ->where('position', $slot['position'])
                ->map(fn (array $item): Player => $selection['lineup'][$courtKeys[$item['slot_number']]]['player']);
            $definitions[] = [
                'slot_number' => $slot['slot_number'],
                'position' => $starter->position,
                'starter_id' => $starter->id,
                'reserve_limit' => $reserves->count(),
                'players' => $samePositionStarters->concat($reserves)->unique('id')->values()->all(),
            ];
            $constraints[] = ['kind' => 'starter', 'player_id' => $starter->id, 'position' => $slot['position']];
        }
        foreach ($bench as $player) {
            $constraints[] = ['kind' => 'reserve_only', 'player_id' => $player->id, 'position' => $player->position->value];
        }
        $optimizer = new TrainingOptimizerService(new TrainingGainCalculator, new SubstitutionPlanGenerator);
        $optimized = $optimizer->optimize($definitions, $scenario, 1, $this->fairnessThreshold, constraints: $constraints)[0] ?? null;
        if (! is_array($optimized)) {
            $this->manualOptimizationError = 'Nie można wyznaczyć planu zmian dla wybranego składu i ograniczeń zawodników.';
            unset($this->manualPreview);

            return;
        }

        $assignments = collect($this->manualAssignments)
            ->reject(fn (mixed $assignment, string $assignmentKey): bool => preg_match('/^\d+-\d+$/', $assignmentKey) === 1)
            ->all();
        foreach ($optimized['plan']['slots'] as $slot) {
            foreach ($slot['sets'] as $set) {
                $assignments[$slot['slot_number'].'-'.$set['set_number']] = (string) $set['active_player']['id'];
            }
        }
        $this->manualAssignments = $assignments;
        unset($this->manualPreview);
    }

    public function resetManualPlan(): void
    {
        $base = collect($this->activeScenario['variants'] ?? [])->firstWhere('variant_key', $this->selectedVariantKey);
        if (! $this->editingVariant || ! is_array($base)) {
            return;
        }

        $this->manualAssignments = $this->initializeManualAssignments($base, []);
        $this->manualOptimizationError = '';
        $this->resetValidation('manual');
        unset($this->manualPreview);
    }

    public function saveManualVariant(): void
    {
        $preview = $this->manualPreview;
        if (! is_array($preview) || ! $preview['is_sendable']) {
            $this->addError('manual', 'Popraw skład i ławkę przed zapisaniem ręcznego planu.');

            return;
        }

        $key = $this->selectedScenarioKey.'|'.$this->selectedVariantKey;
        $this->savedManualVariants[$key] = $this->persistableManualAssignments($this->manualAssignments);
        session()->put('optimizer.manual_variants', ['input_hash' => hash('sha256', json_encode($this->optimizerInput, JSON_THROW_ON_ERROR)), 'assignments' => $this->savedManualVariants]);
        $this->editingVariant = false;
        unset($this->activeVariant, $this->manualPreview);
    }

    #[Computed]
    public function manualPreview(): ?array
    {
        $base = collect($this->activeScenario['variants'] ?? [])->firstWhere('variant_key', $this->selectedVariantKey);
        return is_array($base) ? $this->evaluateManualVariant($base, $this->manualAssignments) : null;
    }

    private function evaluateManualVariant(array $base, array $assignments): ?array
    {
        $scenario = collect($this->scenarioModels)->first(fn (MatchScenario $item): bool => hash('sha256', preg_replace('/\s+/', '', $item->input)) === $this->selectedScenarioKey);
        if (! $scenario) {
            return null;
        }

        $plan = $base['plan'];
        $blockers = $this->editingVariant && $this->manualOptimizationError !== ''
            ? [['message' => $this->manualOptimizationError]]
            : [];
        $players = collect($this->availablePlayersByPosition)->flatMap(fn (array $positionPlayers): array => $positionPlayers)->keyBy('id');
        $starterOverrides = [];
        $planSlotKeys = $this->planSlotCourtKeys($plan);
        foreach ($this->persistableManualAssignments($assignments) as $key => $value) {
            if (is_string($key) && str_starts_with($key, 'starter-')) {
                $starterOverrides[$key] = $value;
            }
        }

        foreach ($plan['slots'] as $slotIndex => &$slot) {
            $courtKey = $planSlotKeys[(int) ($slot['slot_number'] ?? 0)] ?? null;
            $oldStarterId = (int) ($slot['starter']['id'] ?? 0);
            $starter = $courtKey !== null && isset($starterOverrides['starter-'.$courtKey])
                ? $players->get($this->manualPlayerId($starterOverrides['starter-'.$courtKey]) ?? 0)
                : null;

            if ($starter instanceof Player && $starter->position->value === $slot['position']) {
                $slot['starter'] = $this->playerSummary($starter);
            }

            foreach ($slot['sets'] as $setIndex => &$set) {
                $key = $slot['slot_number'].'-'.$set['set_number'];
                $hasExplicitSetAssignment = array_key_exists($key, $assignments);
                $playerId = $hasExplicitSetAssignment
                    ? $this->manualPlayerId($assignments[$key])
                    : (int) ($set['active_player']['id'] ?? 0);
                $player = $playerId === null ? null : $players->get($playerId);

                if (! $hasExplicitSetAssignment && $starter instanceof Player && $starter->position->value === $slot['position'] && (int) ($set['active_player']['id'] ?? 0) === $oldStarterId) {
                    $player = $starter;
                }

                if (! $player instanceof Player || $player->position->value !== $slot['position']) {
                    $blockers[] = ['message' => 'Nieprawidłowy zawodnik w slocie '.$slot['slot_number'].'.'];

                    continue;
                }
                $summary = ['id' => $player->id, 'name' => $player->name, 'position' => $player->position->value, 'training_bar' => $player->training_bar];
                $set['starter_player'] = $slot['starter'];
                $set['active_player'] = $summary;
                $set['substitution_player'] = $player->id === $slot['starter']['id'] ? null : $summary;
                $set['activation_point'] = $set['substitution_player'] ? 1 : null;
                $set['description'] = $set['substitution_player']
                    ? 'Slot '.$slot['slot_number'].' ('.$slot['position_label'].'): '.$slot['starter']['name'].' start, Set '.$set['set_number'].' od 1 punktu -> '.$player->name
                    : 'Slot '.$slot['slot_number'].' ('.$slot['position_label'].'): '.$player->name.' bez zmiany w secie '.$set['set_number'];
            }
            unset($set);
        }
        unset($slot);

        foreach (range(1, $scenario->setsCount()) as $setNumber) {
            $activeIds = collect($plan['slots'])->map(fn (array $slot): int => (int) $slot['sets'][$setNumber - 1]['active_player']['id'])->all();
            if (count($activeIds) !== count(array_unique($activeIds))) {
                $blockers[] = ['message' => 'Ten sam zawodnik zajmuje dwa sloty w secie '.$setNumber.'.'];
            }
        }

        $starterIds = collect($plan['slots'])->pluck('starter.id')->all();
        foreach ($plan['slots'] as $slot) {
            foreach ($slot['sets'] as $set) {
                if ($set['substitution_player'] && in_array($set['active_player']['id'], $starterIds, true)) {
                    $blockers[] = ['message' => 'Starter innego slotu nie może być rezerwowym.'];
                }
            }
        }

        $benchIds = collect($plan['slots'])->flatMap(fn (array $slot): array => collect($slot['sets'])->pluck('substitution_player.id')->filter()->all())->unique();
        if ($benchIds->count() > 5) {
            $blockers[] = ['message' => 'Wspólna ławka przekracza limit pięciu zawodników.'];
        }
        foreach ($this->optimizerInput['reserve_pools'] ?? [] as $pool) {
            if ($benchIds->filter(fn (int $id): bool => $players->get($id)?->position->value === $pool['position'])->count() > $pool['reserve_limit']) {
                $blockers[] = ['message' => 'Przekroczono limit ławki dla pozycji '.$pool['position_label'].'.'];
            }
        }

        if (! (new SubstitutionPlanGenerator)->satisfiesConstraints($plan, $this->optimizerInput['player_constraints'] ?? [])) {
            $blockers[] = ['message' => 'Ręczny plan narusza ograniczenia zawodników.'];
        }

        $optimizer = new TrainingOptimizerService(new TrainingGainCalculator, new SubstitutionPlanGenerator);
        $participantIds = collect($plan['slots'])->flatMap(fn (array $slot): array => [
            $slot['starter']['id'], ...collect($slot['sets'])->pluck('active_player.id')->all(),
        ])->unique()->all();
        $evaluationDefinitions = collect($this->slotDefinitions)->map(fn (array $definition): array => [
            ...$definition,
            'players' => collect($definition['players'])->concat($players->filter(fn (Player $player): bool => $player->position === $definition['position'] && in_array($player->id, $participantIds, true)))->unique('id')->values()->all(),
        ])->all();
        $evaluated = $optimizer->evaluateCustomPlan($plan, $scenario, $evaluationDefinitions, $this->fairnessThreshold);
        $composed = (new VariantLineupComposer)->compose($this->lineupRecommendations['recommendations'][0] ?? [], $plan, $starterOverrides, $this->additionalBenchAssignments($assignments));
        $blockers = [...$blockers, ...$composed['send_blockers']];
        $rulesCount = $this->substitutionRulesCount($plan);
        if ($rulesCount === null) {
            $blockers[] = ['message' => 'Reguły zmian VM są nieprawidłowe.'];
        }

        return [...$base, ...$evaluated, ...$composed, 'substitution_rules_count' => $rulesCount, 'send_blockers' => $blockers, 'is_sendable' => $blockers === [], 'manual' => true];
    }

    public function mount(): void
    {
        $this->optimizerInput = session('optimizer.input', []);
        $stored = session('optimizer.manual_variants', []);
        if (($stored['input_hash'] ?? null) === hash('sha256', json_encode($this->optimizerInput, JSON_THROW_ON_ERROR))) {
            $this->savedManualVariants = $stored['assignments'] ?? [];
        }
        $this->synchronizeSelection();
    }

    public function hydrate(): void
    {
        $this->synchronizeSelection();
    }

    public function selectScenario(string $key): void
    {
        $this->cancelManualEdit();
        $this->compareVariantKey = '';
        $scenario = collect($this->scenarioVariants)->firstWhere('scenario_key', $key);
        if (! is_array($scenario)) {
            return;
        }
        $this->selectedScenarioKey = $key;
        $this->selectedVariantKey = $scenario['variants'][0]['variant_key'] ?? '';
        unset($this->activeScenario, $this->activeVariant);
    }

    public function selectVariant(string $key): void
    {
        if (collect($this->activeScenario['variants'] ?? [])->contains('variant_key', $key)) {
            $this->cancelManualEdit();
            $this->selectedVariantKey = $key;
            unset($this->activeVariant);
        }
    }

    public function requestApplyVariant(): void
    {
        $this->resetValidation('variant');
        $variant = $this->activeVariant;
        if (! is_array($variant) || ! $variant['is_sendable']) {
            $this->addError('variant', 'Wybrany wariant zawiera problemy wymagające poprawy przed wysłaniem.');
            return;
        }
        $this->pendingScenarioKey = $this->selectedScenarioKey;
        $this->pendingVariantKey = $this->selectedVariantKey;
        $this->confirmingVariantApplication = true;
    }

    /** @deprecated The result UI uses selected scenario and variant keys. */
    public function pushSubstitutions(int $planIndex): void
    {
        $variant = $this->rankedPlans[$planIndex] ?? null;

        if (! is_array($variant)) {
            $this->addError('substitutions', 'Nie znaleziono wariantu zmian do wysłania.');

            return;
        }

        if (! $variant['is_sendable']) {
            $this->addError('substitutions', 'Wybrany wariant zawiera problemy wymagające poprawy przed wysłaniem.');

            return;
        }

        $this->pendingScenarioKey = $variant['scenario_key'];
        $this->pendingVariantKey = $variant['variant_key'];
        $this->confirmApplyVariant();
    }

    public function cancelApplyVariant(): void
    {
        $this->confirmingVariantApplication = false;
        $this->pendingScenarioKey = null;
        $this->pendingVariantKey = null;
    }

    public function confirmApplyVariant(): void
    {
        $this->authorizeVmAction();
        $this->validate(['tacticsMatchType' => ['required', Rule::in(array_keys($this->tacticsMatchTypeOptions))]]);
        $this->resetValidation('variant');
        $variant = $this->variantForKeys($this->pendingScenarioKey, $this->pendingVariantKey);
        if (! is_array($variant) || ! $variant['is_sendable']) {
            $this->addError('variant', 'Wybrany wariant nie jest już dostępny lub nie może zostać wysłany.');
            $this->cancelApplyVariant();
            return;
        }
        try {
            $substitutions = app(VmSubstitutionService::class);
            $payloads = $substitutions->buildPayloads($variant['plan'], $this->tacticsMatchType);
            app(VmTacticsService::class)->pushVariantTactics($variant['starter_vm_player_ids'], $variant['bench_vm_player_ids'], $this->tacticsMatchType);
            if ($payloads === []) {
                $this->tacticsStatus = 'Zapisano pełny skład wariantu. Ten wariant nie wymaga zmian w trakcie meczu.';
            } else {
                $result = $substitutions->pushPreparedPayloads($payloads, $this->tacticsMatchType);
                if ($result['error'] !== null) {
                    $this->tacticsStatus = 'Skład i ławka zostały zapisane w VM Managerze.';
                    $this->addError('variant', 'Zmiany nie zostały w pełni zapisane: '.$result['error']);
                } else {
                    $this->tacticsStatus = 'Zapisano cały wariant. Zapisano reguł VM: '.$result['created'].'. Pominięto: '.$result['skipped'].'.';
                }
            }
        } catch (\InvalidArgumentException $exception) {
            $this->addError('variant', $exception->getMessage());
        } catch (\Throwable $exception) {
            report($exception);
            $this->addError('variant', 'Nie udało się wysłać wariantu. Sprawdź połączenie z VM Managerem.');
        }
        $this->cancelApplyVariant();
    }

    public function requestDeleteAllChanges(): void
    {
        $this->authorizeVmAction();
        $this->validate(['tacticsMatchType' => ['required', Rule::in(array_keys($this->tacticsMatchTypeOptions))]]);
        $this->confirmingChangesDeletion = true;
    }

    public function cancelDeleteAllChanges(): void { $this->confirmingChangesDeletion = false; }

    public function deleteAllChanges(): void
    {
        $this->authorizeVmAction();
        try {
            $result = app(VmSubstitutionService::class)->deleteAllTacticsChanges($this->tacticsMatchType);
            $this->changesDeletionStatus = 'Usunięto zmian: '.$result['deleted'].'.';
            if ($result['error'] !== null) {
                $this->addError('changesDeletion', $result['error']);
            }
        } catch (\Throwable $exception) {
            report($exception);
            $this->addError('changesDeletion', 'Nie udało się usunąć zmian.');
        }
        $this->confirmingChangesDeletion = false;
    }

    #[Computed] public function tacticsMatchTypeOptions(): array { return ['League' => 'Liga', 'Cup' => 'Puchar', 'IntCup' => 'Puchar międzynarodowy', 'Friendly' => 'Towarzyski']; }
    #[Computed] public function hasOptimizerInput(): bool { return $this->optimizerInput !== []; }
    #[Computed] public function fairnessThreshold(): int { return max(0, min(100, (int) ($this->optimizerInput['fairness_threshold'] ?? 20))); }

    #[Computed]
    public function availablePlayersByPosition(): array
    {
        return Player::query()
            ->available()
            ->orderBy('training_bar')
            ->orderBy('name')
            ->get()
            ->groupBy(fn (Player $player): string => $player->position->value)
            ->map(fn ($players): array => $players->values()->all())
            ->all();
    }

    /** @return list<Player> */
    public function manualPlayerOptions(string $position): array
    {
        return $this->availablePlayersByPosition[$position] ?? [];
    }

    /** @return list<Player> */
    public function manualCourtPlayerOptions(string $slotKey): array
    {
        foreach (PlayerPosition::cases() as $position) {
            if (in_array($slotKey, $this->courtSlotKeysForPosition($position->value), true)) {
                return $this->manualPlayerOptions($position->value);
            }
        }

        return [];
    }

    /** @return list<mixed> */
    private function additionalBenchAssignments(array $assignments): array
    {
        return collect($assignments)
            ->filter(fn (mixed $value, string $key): bool => str_starts_with($key, 'bench-'))
            ->sortKeys()
            ->values()
            ->all();
    }

    /** @return list<Player> */
    public function manualBenchPlayerOptions(int $slotNumber): array
    {
        $variant = $this->manualPreview;
        $currentId = $this->manualPlayerId($this->manualAssignments['bench-'.$slotNumber] ?? null);
        $occupiedIds = collect($variant['lineup'] ?? [])->pluck('player.id')
            ->merge(collect($variant['bench'] ?? [])->filter()->pluck('id'))
            ->filter()->all();

        return collect($this->availablePlayersByPosition)->flatten(1)
            ->filter(fn (Player $player): bool => $player->id === $currentId || ! in_array($player->id, $occupiedIds, true))
            ->sortBy('name')->values()->all();
    }

    private function initializeManualAssignments(array $variant, array $savedAssignments): array
    {
        $assignments = $savedAssignments;

        foreach ($variant['lineup'] ?? [] as $slot) {
            $slotKey = $slot['key'] ?? null;
            $playerId = $slot['player']?->id ?? null;
            if (is_string($slotKey) && $playerId !== null) {
                $assignments['starter-'.$slotKey] ??= (string) $playerId;
            }
        }

        if (! collect($savedAssignments)->keys()->contains(fn (string $key): bool => str_starts_with($key, 'bench-'))) {
            foreach ($variant['bench'] ?? [] as $index => $player) {
                $assignments['bench-'.($index + 1)] = $player instanceof Player ? (string) $player->id : '';
            }
        }

        return $assignments;
    }

    private function persistableManualAssignments(array $assignments): array
    {
        $base = collect($this->activeScenario['variants'] ?? [])->firstWhere('variant_key', $this->selectedVariantKey);

        return collect($assignments)->reject(function (mixed $value, string $key) use ($base): bool {
            if (! str_starts_with($key, 'starter-')) {
                return false;
            }

            $slotKey = substr($key, strlen('starter-'));
            $defaultId = ($base['lineup'][$slotKey]['player'] ?? null)?->id;

            return $defaultId !== null && $this->manualPlayerId($value) === $defaultId;
        })->all();
    }

    /** @return array<int, string> */
    private function planSlotCourtKeys(array $plan): array
    {
        $keys = [];
        $counts = [];
        foreach (collect($plan['slots'] ?? [])->sortBy('slot_number') as $slot) {
            $position = $slot['position'];
            $index = $counts[$position] ?? 0;
            $courtKey = $this->courtSlotKeysForPosition($position)[$index] ?? null;
            if ($courtKey !== null) {
                $keys[$slot['slot_number']] = $courtKey;
            }
            $counts[$position] = $index + 1;
        }

        return $keys;
    }

    private function manualPlayerId(mixed $value): ?int
    {
        return (is_int($value) || (is_string($value) && ctype_digit($value))) && (int) $value > 0
            ? (int) $value
            : null;
    }

    private function playerSummary(Player $player): array
    {
        return ['id' => $player->id, 'name' => $player->name, 'position' => $player->position->value, 'training_bar' => $player->training_bar];
    }

    #[Computed] public function scenarioModels(): array
    {
        try {
            return collect($this->optimizerInput['scenarios'] ?? [])->filter(fn ($item) => is_array($item) && is_string($item['input'] ?? null))->map(fn ($item) => MatchScenario::fromInput($item['input'], $item['label'] ?? 'Scenariusz'))->all();
        } catch (\InvalidArgumentException) { return []; }
    }

    #[Computed] public function slotDefinitions(): array
    {
        $positions = collect($this->optimizerInput['positions'] ?? []);
        $limits = collect($this->optimizerInput['reserve_pools'] ?? [])->mapWithKeys(fn ($pool) => [$pool['position'] => (int) $pool['reserve_limit']]);
        $players = Player::query()->available()->whereIn('position', $positions->pluck('value'))->orderBy('training_bar')->orderBy('name')->get()->groupBy(fn ($player) => $player->position->value);
        $baseSlots = collect($this->lineupRecommendations['recommendations'][0]['slots'] ?? [])->keyBy('key');
        $lockedPlayerIdsByPosition = $positions->pluck('value')->countBy()->map(function (int $optimizedSlotCount, string $position) use ($baseSlots): array {
            return collect(array_slice($this->courtSlotKeysForPosition($position), $optimizedSlotCount))
                ->map(fn (string $slotKey): ?int => ($baseSlots->get($slotKey)['player'] ?? null)?->id)
                ->filter()
                ->all();
        });

        return $positions->values()->map(function ($position, $index) use ($limits, $players, $lockedPlayerIdsByPosition) {
            $enum = PlayerPosition::from($position['value']);
            $lockedPlayerIds = $lockedPlayerIdsByPosition->get($enum->value, []);

            return ['slot_number' => $index + 1, 'position' => $enum, 'reserve_limit' => $limits[$enum->value] ?? 0, 'players' => ($players[$enum->value] ?? collect())->reject(fn (Player $player): bool => in_array($player->id, $lockedPlayerIds, true))->values()->all()];
        })->all();
    }

    #[Computed] public function lineupRecommendations(): array
    {
        $lineup = (new LineupRecommendationService)->recommend(Player::query()->available()->get());
        $lineup['recommendations'] = collect($lineup['recommendations'] ?? [])->where('kind', 'primary')->values()->all();
        return $lineup;
    }

    #[Computed] public function scenarioRankings(): array
    {
        if (! $this->hasOptimizerInput || $this->scenarioModels === []) {
            return [];
        }
        $optimizer = new TrainingOptimizerService(new TrainingGainCalculator(), new SubstitutionPlanGenerator());
        return collect($this->scenarioModels)->map(fn ($scenario) => ['label' => $scenario->label, 'input' => $scenario->input, 'sets_count' => $scenario->setsCount(), 'plans' => $optimizer->optimize($this->slotDefinitions, $scenario, 3, $this->fairnessThreshold, constraints: $this->optimizerInput['player_constraints'] ?? [])])->all();
    }

    #[Computed] public function scenarioVariants(): array
    {
        $base = $this->lineupRecommendations['recommendations'][0] ?? [];
        return collect($this->scenarioRankings)->map(function ($ranking) use ($base) {
            $scenarioKey = hash('sha256', preg_replace('/\s+/', '', $ranking['input']));
            $variants = collect($ranking['plans'])->values()->map(function ($plan, $index) use ($base, $scenarioKey) {
                $composed = (new VariantLineupComposer)->compose($base, $plan['plan']);
                $substitutionRulesCount = $this->substitutionRulesCount($plan);

                return [...$plan, ...$composed, 'rank' => $index + 1, 'substitution_rules_count' => $substitutionRulesCount, 'variant_key' => hash('sha256', $scenarioKey.'|'.json_encode($this->canonicalPlan($plan), JSON_THROW_ON_ERROR).'|'.json_encode($plan, JSON_THROW_ON_ERROR))];
            })->all();
            $recommended = $variants[0] ?? null;
            $variants = collect($variants)->map(fn ($variant) => [...$variant, 'differences_from_recommendation' => $recommended ? $this->differences($variant, $recommended) : '', 'gain_delta' => $recommended ? $variant['total_gained_training'] - $recommended['total_gained_training'] : 0, 'wasted_delta' => $recommended ? $variant['wasted_actions'] - $recommended['wasted_actions'] : 0, 'players_at_limit' => collect($variant['training_diagnostics']['players'] ?? [])->filter(fn (array $player): bool => $player['limit_reached_set'] !== null && $player['limit_reached_set'] > 0)->count()])->all();
            return ['scenario_key' => $scenarioKey, 'label' => $ranking['label'], 'input' => $ranking['input'], 'sets_count' => $ranking['sets_count'], 'variants' => $variants];
        })->all();
    }

    #[Computed] public function activeScenario(): ?array { return collect($this->scenarioVariants)->firstWhere('scenario_key', $this->selectedScenarioKey) ?? $this->scenarioVariants[0] ?? null; }
    #[Computed]
    public function activeVariant(): ?array
    {
        $variant = collect($this->activeScenario['variants'] ?? [])->firstWhere('variant_key', $this->selectedVariantKey) ?? $this->activeScenario['variants'][0] ?? null;

        if (! is_array($variant)) {
            return null;
        }

        $saved = $this->savedManualVariants[$this->selectedScenarioKey.'|'.$this->selectedVariantKey] ?? null;

        return is_array($saved) ? $this->evaluateManualVariant($variant, $saved) : [...$variant, ...(new VariantLineupComposer)->compose($this->lineupRecommendations['recommendations'][0] ?? [], $variant['plan'])];
    }
    #[Computed] public function pendingVariant(): ?array { return $this->variantForKeys($this->pendingScenarioKey, $this->pendingVariantKey); }
    #[Computed] public function rankedPlans(): array
    {
        return collect($this->scenarioVariants)->flatMap(fn (array $scenario): array => collect($scenario['variants'])->map(fn (array $variant): array => [...$variant, 'scenario_key' => $scenario['scenario_key'], 'scenario_label' => $scenario['label'], 'scenario_rank' => $variant['rank']])->all())->values()->all();
    }

    /** @return list<string> */
    private function courtSlotKeysForPosition(string $position): array
    {
        return collect([
            'setter' => PlayerPosition::Setter->value,
            'outside_1' => PlayerPosition::OutsideHitter->value,
            'middle_1' => PlayerPosition::MiddleBlocker->value,
            'opposite' => PlayerPosition::Opposite->value,
            'outside_2' => PlayerPosition::OutsideHitter->value,
            'middle_2' => PlayerPosition::MiddleBlocker->value,
            'libero' => PlayerPosition::Libero->value,
        ])->filter(fn (string $courtPosition): bool => $courtPosition === $position)->keys()->all();
    }

    private function substitutionRulesCount(array $plan): ?int
    {
        try {
            return count(app(VmSubstitutionService::class)->buildPayloads($plan));
        } catch (\InvalidArgumentException) {
            return null;
        }
    }

    private function synchronizeSelection(): void
    {
        $scenario = $this->activeScenario;
        if (! is_array($scenario)) {
            $this->selectedScenarioKey = '';
            $this->selectedVariantKey = '';

            return;
        }
        $this->selectedScenarioKey = $scenario['scenario_key'];
        if (! collect($scenario['variants'])->contains('variant_key', $this->selectedVariantKey)) {
            $this->selectedVariantKey = $scenario['variants'][0]['variant_key'] ?? '';
        }
    }

    private function variantForKeys(?string $scenarioKey, ?string $variantKey): ?array
    {
        $scenario = collect($this->scenarioVariants)->firstWhere('scenario_key', $scenarioKey);
        $variant = is_array($scenario) ? collect($scenario['variants'])->firstWhere('variant_key', $variantKey) : null;
        $saved = $this->savedManualVariants[$scenarioKey.'|'.$variantKey] ?? null;

        return is_array($saved) && is_array($variant) && $scenarioKey === $this->selectedScenarioKey
            ? $this->evaluateManualVariant($variant, $saved)
            : $variant;
    }

    private function canonicalPlan(array $plan): array
    {
        return collect($plan['slots'] ?? [])->filter(fn (mixed $slot): bool => is_array($slot))->map(fn ($slot) => ['position' => $slot['position'] ?? '', 'slot' => (int) ($slot['slot_number'] ?? 0), 'starter' => (int) ($slot['starter']['id'] ?? 0), 'sets' => collect($slot['sets'] ?? [])->filter(fn (mixed $set): bool => is_array($set))->map(fn ($set) => [(int) ($set['set_number'] ?? 0), (int) ($set['starter_player']['id'] ?? 0), (int) ($set['substitution_player']['id'] ?? 0), (int) ($set['activation_point'] ?? 0)])->sortBy(0)->values()->all()])->sortBy([['position', 'asc'], ['slot', 'asc']])->values()->all();
    }

    private function differences(array $variant, array $recommended): string
    {
        $starters = collect(VmTacticsService::COURT_SLOT_KEYS)->filter(fn ($key) => ($variant['lineup'][$key]['player']?->id ?? null) !== ($recommended['lineup'][$key]['player']?->id ?? null))->count();
        $rules = array_diff(array_map('json_encode', $this->canonicalPlan($variant['plan'])), array_map('json_encode', $this->canonicalPlan($recommended['plan'])));
        return $starters.' innych starterów · '.count($rules).' innych reguł zmian';
    }

    #[Computed]
    public function comparedSlots(): array
    {
        $left = $this->activeVariant;
        $right = collect($this->activeScenario['variants'] ?? [])->firstWhere('variant_key', $this->compareVariantKey);
        if (! $left || ! $right || $left['variant_key'] === $right['variant_key']) {
            return [];
        }

        $rightSlots = collect($right['plan']['slots'])->keyBy('slot_number');
        $differences = [];
        foreach ($left['plan']['slots'] as $slot) {
            $other = $rightSlots->get($slot['slot_number']);
            if (! $other) {
                continue;
            }
            if ($slot['starter']['id'] !== $other['starter']['id']) {
                $differences[] = 'Slot '.$slot['slot_number'].' · starter: '.$slot['starter']['name'].' / '.$other['starter']['name'];
            }
            foreach ($slot['sets'] as $index => $set) {
                if ($set['active_player']['id'] !== $other['sets'][$index]['active_player']['id']) {
                    $differences[] = 'Slot '.$slot['slot_number'].' · set '.$set['set_number'].': '.$set['active_player']['name'].' / '.$other['sets'][$index]['active_player']['name'];
                }
            }
        }

        return $differences;
    }

    private function authorizeVmAction(): void { abort_unless(config('auth.disable_auth') || auth()->check(), 403); }
};
?>

<div class="flex h-full w-full flex-1 flex-col gap-6 p-4 md:p-6">
    <section class="flex flex-col gap-4 rounded-3xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-700 dark:bg-zinc-900 lg:flex-row lg:items-center lg:justify-between">
        <div><flux:heading size="xl" level="1">Wynik optymalizacji</flux:heading><flux:text class="mt-1 text-zinc-600 dark:text-zinc-300">Wybierz scenariusz, potem wariant planu zmian.</flux:text></div>
        <flux:button variant="primary" :href="route('optimizer.create')" wire:navigate>Zmień parametry</flux:button>
    </section>
    @if (! $this->hasOptimizerInput)
        <flux:callout icon="clipboard-document-list" color="amber"><flux:callout.heading>Brak danych wejściowych</flux:callout.heading><flux:callout.text>Najpierw skonfiguruj optymalizację.</flux:callout.text></flux:callout>
    @else
        <section class="rounded-3xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
            <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between"><div class="flex flex-wrap gap-2"><flux:badge color="sky">{{ collect($optimizerInput['positions'] ?? [])->pluck('label')->implode(' + ') }}</flux:badge><flux:badge color="zinc">próg {{ $this->fairnessThreshold }}%</flux:badge><flux:badge color="zinc">{{ count($this->scenarioVariants) }} scenariusze</flux:badge></div><div class="flex gap-3"><flux:select wire:model="tacticsMatchType" label="Typ meczu" class="w-48">@foreach ($this->tacticsMatchTypeOptions as $type => $label)<flux:select.option :value="$type">{{ $label }}</flux:select.option>@endforeach</flux:select><flux:button variant="ghost" icon="trash" wire:click="requestDeleteAllChanges">Usuń wszystkie zmiany</flux:button></div></div>
            <details class="mt-4 rounded-2xl border border-zinc-200 p-4 dark:border-zinc-700"><summary class="cursor-pointer font-medium">Pokaż parametry wejściowe</summary><div class="mt-3 grid gap-3 text-sm text-zinc-600 dark:text-zinc-300 md:grid-cols-2"><div>@foreach ($optimizerInput['reserve_pools'] ?? [] as $pool)<div wire:key="pool-{{ $pool['position'] }}">{{ $pool['position_label'] }}: {{ $pool['reserve_limit'] }} miejsc</div>@endforeach</div><div>@foreach ($optimizerInput['scenarios'] ?? [] as $scenario)<div wire:key="input-{{ $loop->index }}">{{ $scenario['label'] }} · {{ $scenario['input'] }}</div>@endforeach</div></div></details>
        </section>
        @if ($tacticsStatus !== '')<flux:callout icon="check-circle" color="emerald"><flux:callout.heading>Wysłano do VM Managera</flux:callout.heading><flux:callout.text>{{ $tacticsStatus }}</flux:callout.text></flux:callout>@endif
        @error('variant')<flux:callout icon="exclamation-triangle" color="red"><flux:callout.heading>Nie wysłano wariantu</flux:callout.heading><flux:callout.text>{{ $message }}</flux:callout.text></flux:callout>@enderror
        @if ($this->scenarioVariants === [])<flux:callout icon="users" color="amber"><flux:callout.heading>Brak legalnych wariantów</flux:callout.heading><flux:callout.text>Uzupełnij aktywnych zawodników i konfigurację.</flux:callout.text></flux:callout>@else
            <section class="rounded-3xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900"><flux:text class="text-sm font-medium uppercase tracking-[0.18em] text-zinc-500">Scenariusze meczu</flux:text><div class="mt-4 flex flex-wrap gap-2">@foreach ($this->scenarioVariants as $scenario)<flux:button wire:key="scenario-{{ $scenario['scenario_key'] }}" size="sm" :variant="$scenario['scenario_key'] === $selectedScenarioKey ? 'primary' : 'ghost'" wire:click="selectScenario('{{ $scenario['scenario_key'] }}')">{{ $scenario['label'] }} · {{ count($scenario['variants']) }} warianty</flux:button>@endforeach</div></section>
            @php $scenario = $this->activeScenario; @endphp @php $previousVariant = $this->activeVariant; @endphp @php $variant = $editingVariant ? $this->manualPreview : $previousVariant; @endphp
            @if ($scenario && ! $variant)<flux:callout icon="exclamation-triangle" color="amber"><flux:callout.heading>Brak wariantów dla scenariusza</flux:callout.heading><flux:callout.text>Sprawdź dostępność zawodników, limity ławki i ograniczenia wejściowe.</flux:callout.text></flux:callout>@endif
            @if ($scenario && $variant)
                <section class="rounded-3xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-700 dark:bg-zinc-900"><flux:heading size="lg">{{ $scenario['label'] }}</flux:heading><flux:text class="mt-1 text-sm text-zinc-600 dark:text-zinc-300">{{ $scenario['input'] }} · {{ $scenario['sets_count'] }} sety</flux:text><div class="mt-5 grid gap-3 md:grid-cols-3">@foreach ($scenario['variants'] as $item)<button type="button" wire:key="variant-{{ $item['variant_key'] }}" wire:click="selectVariant('{{ $item['variant_key'] }}')" @class(['rounded-2xl border p-4 text-left', 'border-sky-500 bg-sky-50 dark:bg-sky-950/30' => $item['variant_key'] === $selectedVariantKey, 'border-zinc-200 bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-800' => $item['variant_key'] !== $selectedVariantKey])><div class="flex justify-between"><span class="font-semibold">#{{ $item['rank'] }}</span>@if ($item['rank'] === 1)<flux:badge color="emerald">Rekomendowany</flux:badge>@endif</div><div class="mt-3 text-xl font-semibold">+{{ $item['total_gained_training'] }} treningu</div><div class="mt-2 grid grid-cols-2 gap-1 text-xs text-zinc-600 dark:text-zinc-300"><span>min. {{ $item['lowest_final_training_bar'] }}%</span><span>{{ $item['players_below_fairness_threshold'] }} poniżej progu</span><span>{{ $item['wasted_actions'] }} stratnych akcji ({{ sprintf('%+d', $item['wasted_delta']) }})</span><span>{{ $item['substitutions_count'] }} zmian · {{ $item['substitution_rules_count'] ?? '—' }} reguł VM</span><span>{{ $item['players_at_limit'] }} osiąga limit</span><span>{{ sprintf('%+d', $item['gain_delta']) }} treningu do rekomendacji</span></div>@if ($item['rank'] > 1 && $item['gain_delta'] === 0 && $item['substitutions_count'] !== $scenario['variants'][0]['substitutions_count'])<p class="mt-2 text-xs text-emerald-700">Ten sam zysk; {{ $item['substitutions_count'] < $scenario['variants'][0]['substitutions_count'] ? 'ten wariant' : 'rekomendacja' }} wymaga mniej zmian.</p>@endif @if ($item['rank'] > 1)<p class="mt-3 text-xs text-zinc-600 dark:text-zinc-300">{{ $item['differences_from_recommendation'] }}</p>@endif</button>@endforeach</div>
                    <div class="mt-5"><flux:select wire:model.live="compareVariantKey" label="Porównaj z wariantem" class="max-w-xs"><option value="">Wybierz wariant</option>@foreach ($scenario['variants'] as $item)<option value="{{ $item['variant_key'] }}">Wariant #{{ $item['rank'] }}</option>@endforeach</flux:select>@if ($compareVariantKey !== '')<ul class="mt-3 space-y-1 text-sm">@forelse ($this->comparedSlots as $difference)<li>{{ $difference }}</li>@empty<li>Brak różnic w slotach i setach.</li>@endforelse</ul>@endif</div>
                </section>
                <section class="rounded-3xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-700 dark:bg-zinc-900"><div class="flex flex-col gap-3 md:flex-row md:items-center md:justify-between"><div><flux:heading size="lg">Wariant #{{ $variant['rank'] }}{{ $editingVariant || ($variant['manual'] ?? false) ? ' · ręczny' : '' }}</flux:heading><flux:text class="mt-1 text-sm text-zinc-600 dark:text-zinc-300">Pełny skład, ławka i plan wybranego wariantu.</flux:text></div><div class="flex flex-wrap gap-2">@if ($editingVariant)<flux:button wire:click="cancelManualEdit">Anuluj edycję</flux:button><flux:button variant="primary" wire:click="saveManualVariant" :disabled="! $variant['is_sendable']">Zapisz ręczny plan</flux:button>@else<flux:button wire:click="startManualEdit">Edytuj wariant</flux:button><flux:button variant="primary" wire:click="requestApplyVariant" :disabled="! $variant['is_sendable']">Wyślij ten wariant do VM Managera</flux:button>@endif</div></div>
                    @if ($editingVariant)
                        @php
                            $manualChangesCount = collect($variant['plan']['slots'])->sum(function (array $slot) use ($previousVariant): int {
                                $previousSlot = collect($previousVariant['plan']['slots'])->firstWhere('slot_number', $slot['slot_number']);

                                if (! is_array($previousSlot)) {
                                    return 0;
                                }

                                return collect($slot['sets'])->filter(function (array $set) use ($previousSlot): bool {
                                    $previousSet = collect($previousSlot['sets'])->firstWhere('set_number', $set['set_number']);

                                    return is_array($previousSet)
                                        && (int) $set['active_player']['id'] !== (int) $previousSet['active_player']['id'];
                                })->count();
                            });
                            $trainingDelta = $variant['total_gained_training'] - $previousVariant['total_gained_training'];
                            $wastedDelta = $variant['wasted_actions'] - $previousVariant['wasted_actions'];
                        @endphp

                        <div class="mt-5 overflow-visible rounded-2xl border border-sky-300 bg-white dark:border-sky-800 dark:bg-zinc-900">
                            <div class="flex items-center justify-between gap-4 border-b border-sky-100 px-5 py-4 dark:border-sky-900">
                                <div>
                                    <div class="flex flex-wrap items-center gap-2">
                                        <flux:heading size="sm">Plan zmian · tryb edycji</flux:heading>
                                        <flux:badge color="sky">Ręcznych korekt: {{ $manualChangesCount }}</flux:badge>
                                    </div>
                                    <flux:text class="mt-1 text-xs text-zinc-600 dark:text-zinc-300">
                                        Wiersz oznacza slot, kolumna set. Kliknij zawodnika w komórce, aby zmienić plan dla konkretnego seta.
                                    </flux:text>
                                </div>

                                <div class="flex shrink-0 gap-2 text-xs">
                                    <flux:badge :color="$trainingDelta > 0 ? 'emerald' : ($trainingDelta < 0 ? 'amber' : 'zinc')">
                                        Δ treningu {{ sprintf('%+d', $trainingDelta) }}
                                    </flux:badge>
                                    <flux:badge :color="$wastedDelta < 0 ? 'emerald' : ($wastedDelta > 0 ? 'amber' : 'zinc')">
                                        Δ strat {{ sprintf('%+d', $wastedDelta) }}
                                    </flux:badge>
                                </div>
                            </div>

                            <div class="overflow-visible">
                                <table class="w-full table-fixed border-collapse text-left">
                                    <thead>
                                        <tr class="bg-zinc-50 text-xs uppercase tracking-wide text-zinc-500 dark:bg-zinc-800/70 dark:text-zinc-400">
                                            <th class="w-56 border-b border-r border-zinc-200 px-4 py-3 font-medium dark:border-zinc-700">Slot / starter</th>
                                            @for ($setNumber = 1; $setNumber <= $scenario['sets_count']; $setNumber++)
                                                <th class="border-b border-zinc-200 px-3 py-3 text-center font-medium dark:border-zinc-700">Set {{ $setNumber }}</th>
                                            @endfor
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                                        @foreach ($variant['plan']['slots'] as $slot)
                                            @php
                                                $previousSlot = collect($previousVariant['plan']['slots'])->firstWhere('slot_number', $slot['slot_number']);
                                                $slotPlayers = collect($this->manualPlayerOptions($slot['position']));
                                                $otherStarterIds = collect($variant['plan']['slots'])
                                                    ->reject(fn (array $candidateSlot): bool => $candidateSlot['slot_number'] === $slot['slot_number'])
                                                    ->pluck('starter.id')
                                                    ->map(fn ($id): int => (int) $id)
                                                    ->all();
                                            @endphp

                                            <tr wire:key="manual-row-{{ $slot['slot_number'] }}" class="align-top">
                                                <th class="border-r border-zinc-200 bg-zinc-50/70 px-4 py-4 dark:border-zinc-700 dark:bg-zinc-800/40">
                                                    <div class="flex items-start justify-between gap-3">
                                                        <div class="min-w-0">
                                                            <div class="text-sm font-semibold text-zinc-900 dark:text-zinc-100">
                                                                Slot {{ $slot['slot_number'] }} · {{ $slot['position_label'] }}
                                                            </div>
                                                            <div class="mt-1 truncate text-xs text-zinc-600 dark:text-zinc-300">
                                                                Starter: {{ $slot['starter']['name'] }} · {{ $slot['starter']['training_bar'] }}%
                                                            </div>
                                                        </div>
                                                        <flux:badge color="zinc">{{ $slot['position_label'] }}</flux:badge>
                                                    </div>
                                                </th>

                                                @foreach ($slot['sets'] as $set)
                                                    @php
                                                        $previousSet = is_array($previousSlot)
                                                            ? collect($previousSlot['sets'])->firstWhere('set_number', $set['set_number'])
                                                            : null;
                                                        $isManualChange = is_array($previousSet)
                                                            && (int) $set['active_player']['id'] !== (int) $previousSet['active_player']['id'];
                                                        $otherActiveIds = collect($variant['plan']['slots'])
                                                            ->reject(fn (array $candidateSlot): bool => $candidateSlot['slot_number'] === $slot['slot_number'])
                                                            ->map(function (array $candidateSlot) use ($set): ?int {
                                                                $candidateSet = collect($candidateSlot['sets'])->firstWhere('set_number', $set['set_number']);

                                                                return is_array($candidateSet) ? (int) $candidateSet['active_player']['id'] : null;
                                                            })
                                                            ->filter()
                                                            ->all();
                                                        $assignmentKey = $slot['slot_number'].'-'.$set['set_number'];
                                                    @endphp

                                                    <td wire:key="manual-cell-{{ $slot['slot_number'] }}-{{ $set['set_number'] }}" @class([
                                                        'p-2',
                                                        'bg-sky-50/70 dark:bg-sky-950/20' => $isManualChange,
                                                    ])>
                                                        <flux:dropdown position="bottom" align="start">
                                                            <button
                                                                type="button"
                                                                @class([
                                                                    'group relative flex min-h-20 w-full flex-col justify-center rounded-xl border px-3 py-2.5 text-left transition hover:border-sky-400 hover:bg-sky-50 focus:outline-none focus:ring-2 focus:ring-sky-500/30 dark:hover:border-sky-600 dark:hover:bg-sky-950/30',
                                                                    'border-sky-400 bg-sky-50 dark:border-sky-700 dark:bg-sky-950/30' => $isManualChange,
                                                                    'border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900' => ! $isManualChange,
                                                                ])
                                                            >
                                                                <div class="flex items-center justify-between gap-2">
                                                                    <span class="min-w-0 truncate text-sm font-medium text-zinc-900 dark:text-zinc-100">
                                                                        {{ $set['substitution_player'] ? '→ ' : '' }}{{ $set['active_player']['name'] }}
                                                                    </span>
                                                                    <span class="shrink-0 text-xs font-medium text-zinc-500 dark:text-zinc-400">{{ $set['active_player']['training_bar'] }}%</span>
                                                                </div>
                                                                <div class="mt-1 flex items-center justify-between gap-2">
                                                                    <span class="text-xs text-zinc-500 dark:text-zinc-400">
                                                                        {{ $set['substitution_player'] ? 'zmiana od 1. punktu' : 'bez zmiany' }}
                                                                    </span>
                                                                    @if ($isManualChange)
                                                                        <span class="rounded-full bg-sky-100 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-sky-700 dark:bg-sky-900/60 dark:text-sky-200">ręcznie</span>
                                                                    @endif
                                                                </div>
                                                            </button>

                                                            <flux:menu class="min-w-72">
                                                                <div class="px-2 pb-2 pt-1 text-xs font-medium text-zinc-500 dark:text-zinc-400">
                                                                    Set {{ $set['set_number'] }} · {{ $slot['position_label'] }}
                                                                </div>

                                                                @foreach ($slotPlayers as $player)
                                                                    @php
                                                                        $isActiveElsewhere = in_array($player->id, $otherActiveIds, true);
                                                                        $isOtherStarter = in_array($player->id, $otherStarterIds, true);
                                                                        $isUnavailable = $isActiveElsewhere || $isOtherStarter;
                                                                        $isSelected = (int) $set['active_player']['id'] === (int) $player->id;
                                                                    @endphp

                                                                    <flux:menu.item
                                                                        as="button"
                                                                        type="button"
                                                                        wire:key="manual-option-{{ $slot['slot_number'] }}-{{ $set['set_number'] }}-{{ $player->id }}"
                                                                        wire:click="$set('manualAssignments.{{ $assignmentKey }}', {{ $player->id }})"
                                                                        :disabled="$isUnavailable"
                                                                    >
                                                                        <div class="flex w-full items-center justify-between gap-4">
                                                                            <span class="min-w-0 truncate">{{ $player->name }}</span>
                                                                            <span class="shrink-0 text-xs text-zinc-500">{{ $player->training_bar }}%</span>
                                                                        </div>

                                                                        @if ($isSelected)
                                                                            <div class="mt-0.5 text-[11px] text-sky-600 dark:text-sky-300">wybrany</div>
                                                                        @elseif ($isActiveElsewhere)
                                                                            <div class="mt-0.5 text-[11px] text-zinc-400">gra już w tym secie</div>
                                                                        @elseif ($isOtherStarter)
                                                                            <div class="mt-0.5 text-[11px] text-zinc-400">starter innego slotu</div>
                                                                        @endif
                                                                    </flux:menu.item>
                                                                @endforeach
                                                            </flux:menu>
                                                        </flux:dropdown>
                                                    </td>
                                                @endforeach
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>

                            <div class="flex items-center justify-between gap-4 border-t border-sky-100 px-5 py-3 dark:border-sky-900">
                                <flux:text class="text-xs text-zinc-500 dark:text-zinc-400">
                                    Pełny skład i ławka poniżej są przeliczane po każdej zmianie.
                                </flux:text>
                                <flux:button size="sm" variant="ghost" wire:click="resetManualPlan" :disabled="$manualChangesCount === 0">
                                    Przywróć plan optymalizatora
                                </flux:button>
                            </div>

                            @error('manual')
                                <p class="border-t border-rose-200 px-5 py-3 text-sm text-rose-700 dark:border-rose-900 dark:text-rose-300">{{ $message }}</p>
                            @enderror
                        </div>
                    @endif
                    <div class="mt-6 grid gap-6 lg:grid-cols-[minmax(0,1fr)_20rem]"><div><flux:heading size="sm">Pełny skład</flux:heading><div class="mt-4 grid grid-cols-3 gap-3">@foreach ($variant['lineup'] as $slot)<div wire:key="lineup-{{ $variant['variant_key'] }}-{{ $slot['key'] }}" @class(['rounded-xl border p-3 text-center', 'border-zinc-200 bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-800' => $slot['player'], 'border-dashed border-amber-300 p-3 text-center' => ! $slot['player']]) style="grid-row: {{ $slot['grid_row'] ?? 'auto' }}; grid-column: {{ $slot['grid_column'] ?? 'auto' }};"><flux:text class="text-xs uppercase text-zinc-500">{{ $slot['abbreviation'] ?? $slot['key'] }}</flux:text>@if ($editingVariant)
                            <flux:select class="mt-2" wire:model.live="manualAssignments.starter-{{ $slot['key'] }}" aria-label="Zawodnik: {{ $slot['label'] ?? $slot['key'] }}">
                                <option value="" disabled>Wybierz zawodnika</option>
                                @foreach ($this->manualCourtPlayerOptions($slot['key']) as $player)
                                    <option value="{{ $player->id }}">{{ $player->name }} · {{ $player->training_bar }}%</option>
                                @endforeach
                            </flux:select>
                        @else
                            <flux:text class="mt-1 text-sm font-medium">{{ $slot['player']?->name ?? 'Brak' }}</flux:text>
                        @endif@if ($slot['player'])<flux:text class="mt-1 text-xs text-zinc-600 dark:text-zinc-300">{{ $slot['player']->position->label() }} · {{ $slot['player']->training_bar }}%</flux:text><flux:badge class="mt-2" :color="$slot['source'] === 'base' ? 'zinc' : 'sky'">{{ $slot['source'] === 'manual' ? 'ręczny' : ($slot['source'] === 'optimized' ? 'optymalizowany' : 'bazowy') }}</flux:badge>@endif</div>@endforeach</div></div><div class="rounded-2xl border border-zinc-200 p-4 dark:border-zinc-700"><flux:heading size="sm">Ławka wariantu</flux:heading><flux:text class="mt-1 text-xs text-zinc-600 dark:text-zinc-300">Rezerwowi planu zmian i dodatkowi zawodnicy.</flux:text>@if (! $editingVariant)<ul class="mt-4 space-y-2">@forelse (collect($variant['bench'])->filter() as $player)<li wire:key="bench-{{ $variant['variant_key'] }}-{{ $player->id }}" class="rounded-xl bg-zinc-50 p-3 dark:bg-zinc-800">{{ $player->name }} · {{ $player->position->label() }} · {{ $player->training_bar }}%</li>@empty<li class="text-sm text-zinc-600 dark:text-zinc-300">Ten wariant nie wymaga dodatkowych zawodników na ławce.</li>@endforelse</ul>@endif@if ($editingVariant)
                            <div class="mt-4 space-y-3">
                                @foreach (range(1, 5) as $benchSlot)
                                    <div wire:key="manual-bench-{{ $variant['variant_key'] }}-{{ $benchSlot }}">
                                        <flux:select wire:model.live="manualAssignments.bench-{{ $benchSlot }}" label="Rezerwowy {{ $benchSlot }}">
                                            <option value="">Wolne miejsce</option>
                                            @foreach ($this->manualBenchPlayerOptions($benchSlot) as $player)
                                                <option value="{{ $player->id }}">{{ $player->name }} · {{ $player->position->label() }} · {{ $player->training_bar }}%</option>
                                            @endforeach
                                        </flux:select>
                                    </div>
                                @endforeach
                            </div>
                            <flux:text class="mt-3 text-xs">Zmiana składu lub ławki automatycznie przelicza plan dla analizowanych pozycji. Ręczne korekty setów zostaną zastąpione.</flux:text>
                        @endif
                        <flux:text class="mt-4 text-sm">{{ count(collect($variant['bench'])->filter()) }} zajęte · {{ count(collect($variant['bench'])->filter(fn ($player) => $player === null)) }} wolne miejsca</flux:text></div></div>
                    @if ($variant['send_blockers'] !== [])<flux:callout class="mt-6" icon="exclamation-triangle" color="red"><flux:callout.heading>Wariant nie może zostać wysłany</flux:callout.heading><flux:callout.text><ul class="mt-2 list-disc pl-5">@foreach ($variant['send_blockers'] as $blocker)<li wire:key="blocker-{{ $variant['variant_key'] }}-{{ $loop->index }}">{{ $blocker['message'] }}</li>@endforeach</ul></flux:callout.text></flux:callout>@endif
                    @php $diagnostics = $variant['training_diagnostics']; @endphp
                    <div class="mt-6 rounded-2xl bg-zinc-50 p-4 dark:bg-zinc-800">
                        <flux:heading size="sm">Wykorzystanie treningu</flux:heading>
                        @if (($variant['refinement_gained_training'] ?? 0) > 0)
                            <p class="mt-2 text-sm text-emerald-700 dark:text-emerald-300">Automatyczna korekta planu: +{{ $variant['refinement_gained_training'] }} treningu i {{ $variant['refinement_gained_training'] }} mniej straconych akcji.</p>
                        @endif
                        <div class="mt-2 flex flex-wrap gap-x-6 gap-y-2 text-sm">
                            <span>Straty łącznie: {{ $variant['wasted_actions'] }}</span>
                            <span>Teoretyczne minimum strat: {{ $diagnostics['minimum_wasted_actions'] }}</span>
                            <span>Straty ponad minimum: {{ $diagnostics['excess_wasted_actions'] }}</span>
                        </div>
                        <flux:text class="mt-2 text-xs">Dane dotyczą analizowanych pozycji. Minimum wynika z liczby akcji i limitów treningu zawodników. Dozwolone zmiany mogą uniemożliwić jego osiągnięcie.</flux:text>
                    </div>
                    @if (! $editingVariant)
                    <div class="mt-6">
                        <flux:heading size="sm">Plan zmian</flux:heading>
                        @php $sets = collect($variant['plan']['slots'])->flatMap(fn ($slot) => $slot['sets'])->groupBy('set_number')->sortKeys(); @endphp
                        <div class="mt-3 grid gap-3 md:grid-cols-2">
                            @forelse ($sets as $number => $rules)
                                @php $setResult = $diagnostics['sets'][$number]; @endphp
                                <div wire:key="set-{{ $variant['variant_key'] }}-{{ $number }}" class="rounded-2xl border border-zinc-200 p-4 dark:border-zinc-700">
                                    <div class="flex flex-wrap items-center justify-between gap-2">
                                        <flux:text class="font-medium">Set {{ $number }}</flux:text>
                                        <span @class(['text-xs', 'text-amber-700 dark:text-amber-300' => $setResult['wasted_actions'] > 0, 'text-zinc-500 dark:text-zinc-400' => $setResult['wasted_actions'] === 0])>Straty w secie: {{ $setResult['wasted_actions'] }}</span>
                                    </div>
                                    <ul class="mt-2 space-y-1 text-sm text-zinc-600 dark:text-zinc-300">
                                        @foreach ($rules as $rule)
                                            <li wire:key="rule-{{ $variant['variant_key'] }}-{{ $number }}-{{ $loop->index }}">{{ $rule['description'] }}</li>
                                        @endforeach
                                    </ul>
                                    @if ($setResult['wasted_actions'] > 0)
                                        <ul class="mt-3 space-y-1 border-t border-zinc-100 pt-3 text-xs text-amber-700 dark:border-zinc-700 dark:text-amber-300">
                                            @foreach ($setResult['players'] as $playerResult)
                                                @if ($playerResult['wasted_actions'] > 0)
                                                    <li wire:key="loss-{{ $variant['variant_key'] }}-{{ $number }}-{{ $playerResult['id'] }}">{{ $playerResult['name'] }}: {{ $playerResult['wasted_actions'] }} strat</li>
                                                @endif
                                            @endforeach
                                        </ul>
                                    @endif
                                </div>
                            @empty
                                <flux:text class="text-sm text-zinc-600 dark:text-zinc-300">Plan nie wymaga zmian w trakcie meczu.</flux:text>
                            @endforelse
                        </div>
                    </div>
                    @endif
                    <div class="mt-6 overflow-x-auto">
                        <flux:heading size="sm">Efekt treningowy</flux:heading>
                        <table class="mt-3 min-w-full text-left text-sm">
                            <thead>
                                <tr class="border-b border-zinc-200 text-zinc-500 dark:border-zinc-700">
                                    <th class="p-2">Zawodnik</th><th class="p-2">Pozycja</th><th class="p-2">Start</th><th class="p-2">Akcje</th><th class="p-2">Zysk</th><th class="p-2">Straty</th><th class="p-2">Koniec</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($variant['player_results'] as $result)
                                    @php $playerDiagnostics = $diagnostics['players'][$result['id']]; @endphp
                                    <tr wire:key="result-{{ $variant['variant_key'] }}-{{ $result['id'] }}" class="border-b border-zinc-100 dark:border-zinc-800">
                                        <td class="p-2">
                                            {{ $result['name'] }}
                                            @if ($playerDiagnostics['limit_reached_set'] === 0)
                                                <div class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">Pełny pasek przed meczem</div>
                                            @elseif ($playerDiagnostics['limit_reached_set'] !== null)
                                                <div class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">Limit +{{ $playerDiagnostics['capacity'] }} osiągnięty w secie {{ $playerDiagnostics['limit_reached_set'] }}</div>
                                            @endif
                                        </td>
                                        <td class="p-2">{{ $result['position_label'] }}</td>
                                        <td class="p-2">{{ $result['starting_training_bar'] }}%</td>
                                        <td class="p-2">{{ $result['played_actions'] }}</td>
                                        <td class="p-2">+{{ $result['gained_training'] }}%</td>
                                        <td @class(['p-2', 'font-medium text-amber-700 dark:text-amber-300' => $result['wasted_actions'] > 0])>{{ $result['wasted_actions'] }}</td>
                                        <td class="p-2">{{ $result['final_training_bar'] }}%</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </section>
            @endif
        @endif
    @endif
    <flux:modal wire:model="confirmingVariantApplication"><flux:heading size="lg">Wyślij wariant do VM Managera</flux:heading>@php $pending = $this->pendingVariant; @endphp @if ($pending)<flux:text class="mt-2">Wariant #{{ $pending['rank'] }} · {{ $this->tacticsMatchTypeOptions[$tacticsMatchType] ?? $tacticsMatchType }}</flux:text><ul class="mt-4 text-sm">@foreach ($pending['lineup'] as $slot)<li wire:key="pending-{{ $slot['key'] }}">{{ $slot['label'] ?? $slot['key'] }}: {{ $slot['player']?->name ?? 'Brak' }}</li>@endforeach</ul><flux:text class="mt-4">Ławka: {{ count(collect($pending['bench'])->filter()) }} · Reguły VM: {{ $pending['substitution_rules_count'] ?? 'nieprawidłowe' }} · Zdarzenia w setach: {{ $pending['substitutions_count'] }}</flux:text>@endif<div class="mt-6 flex justify-end gap-3"><flux:button variant="ghost" wire:click="cancelApplyVariant">Anuluj</flux:button><flux:button variant="primary" wire:click="confirmApplyVariant">Wyślij ten wariant do VM Managera</flux:button></div></flux:modal>
    <flux:modal wire:model="confirmingChangesDeletion"><flux:heading size="lg">Usunąć wszystkie zmiany?</flux:heading><div class="mt-6 flex justify-end gap-3"><flux:button variant="ghost" wire:click="cancelDeleteAllChanges">Anuluj</flux:button><flux:button variant="danger" wire:click="deleteAllChanges">Usuń zmiany</flux:button></div></flux:modal>
</div>
