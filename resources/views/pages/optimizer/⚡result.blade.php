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

    public function mount(): void
    {
        $this->optimizerInput = session('optimizer.input', []);
        $this->synchronizeSelection();
    }

    public function hydrate(): void
    {
        $this->synchronizeSelection();
    }

    public function selectScenario(string $key): void
    {
        $scenario = collect($this->scenarioVariants)->firstWhere('scenario_key', $key);
        if (! is_array($scenario)) {
            return;
        }
        $this->selectedScenarioKey = $key;
        $this->selectedVariantKey = $scenario['variants'][0]['variant_key'] ?? '';
    }

    public function selectVariant(string $key): void
    {
        if (collect($this->activeScenario['variants'] ?? [])->contains('variant_key', $key)) {
            $this->selectedVariantKey = $key;
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
        return collect($this->scenarioModels)->map(fn ($scenario) => ['label' => $scenario->label, 'input' => $scenario->input, 'sets_count' => $scenario->setsCount(), 'plans' => $optimizer->optimize($this->slotDefinitions, $scenario, 3, $this->fairnessThreshold)])->all();
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
            $variants = collect($variants)->map(fn ($variant) => [...$variant, 'differences_from_recommendation' => $recommended ? $this->differences($variant, $recommended) : ''])->all();
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

        return [...$variant, ...(new VariantLineupComposer)->compose($this->lineupRecommendations['recommendations'][0] ?? [], $variant['plan'])];
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
        return is_array($scenario) ? collect($scenario['variants'])->firstWhere('variant_key', $variantKey) : null;
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
            @php($scenario = $this->activeScenario) @php($variant = $this->activeVariant)
            @if ($scenario && $variant)
                <section class="rounded-3xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-700 dark:bg-zinc-900"><flux:heading size="lg">{{ $scenario['label'] }}</flux:heading><flux:text class="mt-1 text-sm text-zinc-600 dark:text-zinc-300">{{ $scenario['input'] }} · {{ $scenario['sets_count'] }} sety</flux:text><div class="mt-5 grid gap-3 md:grid-cols-3">@foreach ($scenario['variants'] as $item)<button type="button" wire:key="variant-{{ $item['variant_key'] }}" wire:click="selectVariant('{{ $item['variant_key'] }}')" @class(['rounded-2xl border p-4 text-left', 'border-sky-500 bg-sky-50 dark:bg-sky-950/30' => $item['variant_key'] === $selectedVariantKey, 'border-zinc-200 bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-800' => $item['variant_key'] !== $selectedVariantKey])><div class="flex justify-between"><span class="font-semibold">#{{ $item['rank'] }}</span>@if ($item['rank'] === 1)<flux:badge color="emerald">Rekomendowany</flux:badge>@endif</div><div class="mt-3 text-xl font-semibold">+{{ $item['total_gained_training'] }} treningu</div><div class="mt-2 grid grid-cols-2 gap-1 text-xs text-zinc-600 dark:text-zinc-300"><span>min. {{ $item['lowest_final_training_bar'] }}%</span><span>{{ $item['players_below_fairness_threshold'] }} poniżej progu</span><span>{{ $item['wasted_actions'] }} zmarnowanych</span><span>{{ $item['substitution_rules_count'] ?? '—' }} reguł zmian</span></div>@if ($item['rank'] > 1)<p class="mt-3 text-xs text-zinc-600 dark:text-zinc-300">{{ $item['differences_from_recommendation'] }}</p>@endif</button>@endforeach</div></section>
                <section class="rounded-3xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-700 dark:bg-zinc-900"><div class="flex flex-col gap-3 md:flex-row md:items-center md:justify-between"><div><flux:heading size="lg">Wariant #{{ $variant['rank'] }}</flux:heading><flux:text class="mt-1 text-sm text-zinc-600 dark:text-zinc-300">Pełny skład, ławka i plan wybranego wariantu.</flux:text></div><flux:button variant="primary" wire:click="requestApplyVariant" :disabled="! $variant['is_sendable']">Wyślij ten wariant do VM Managera</flux:button></div>
                    <div class="mt-6 grid gap-6 lg:grid-cols-[minmax(0,1fr)_20rem]"><div><flux:heading size="sm">Pełny skład</flux:heading><div class="mt-4 grid grid-cols-3 gap-3">@foreach ($variant['lineup'] as $slot)<div wire:key="lineup-{{ $variant['variant_key'] }}-{{ $slot['key'] }}" @class(['rounded-xl border p-3 text-center', 'border-zinc-200 bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-800' => $slot['player'], 'border-dashed border-amber-300 p-3 text-center' => ! $slot['player']]) style="grid-row: {{ $slot['grid_row'] ?? 'auto' }}; grid-column: {{ $slot['grid_column'] ?? 'auto' }};"><flux:text class="text-xs uppercase text-zinc-500">{{ $slot['abbreviation'] ?? $slot['key'] }}</flux:text><flux:text class="mt-1 text-sm font-medium">{{ $slot['player']?->name ?? 'Brak' }}</flux:text>@if ($slot['player'])<flux:text class="mt-1 text-xs text-zinc-600 dark:text-zinc-300">{{ $slot['player']->position->label() }} · {{ $slot['player']->training_bar }}%</flux:text><flux:badge class="mt-2" :color="$slot['source'] === 'optimized' ? 'sky' : 'zinc'">{{ $slot['source'] === 'optimized' ? 'optymalizowany' : 'bazowy' }}</flux:badge>@endif</div>@endforeach</div></div><div class="rounded-2xl border border-zinc-200 p-4 dark:border-zinc-700"><flux:heading size="sm">Ławka wariantu</flux:heading><flux:text class="mt-1 text-xs text-zinc-600 dark:text-zinc-300">Wymagana przez ten konkretny wariant.</flux:text><ul class="mt-4 space-y-2">@forelse (collect($variant['bench'])->filter() as $player)<li wire:key="bench-{{ $variant['variant_key'] }}-{{ $player->id }}" class="rounded-xl bg-zinc-50 p-3 dark:bg-zinc-800">{{ $player->name }} · {{ $player->position->label() }} · {{ $player->training_bar }}%</li>@empty<li class="text-sm text-zinc-600 dark:text-zinc-300">Ten wariant nie wymaga dodatkowych zawodników na ławce.</li>@endforelse</ul><flux:text class="mt-4 text-sm">{{ count(collect($variant['bench'])->filter()) }} zajęte · {{ count(collect($variant['bench'])->filter(fn ($player) => $player === null)) }} wolne miejsca</flux:text></div></div>
                    @if ($variant['send_blockers'] !== [])<flux:callout class="mt-6" icon="exclamation-triangle" color="red"><flux:callout.heading>Wariant nie może zostać wysłany</flux:callout.heading><flux:callout.text><ul class="mt-2 list-disc pl-5">@foreach ($variant['send_blockers'] as $blocker)<li wire:key="blocker-{{ $variant['variant_key'] }}-{{ $loop->index }}">{{ $blocker['message'] }}</li>@endforeach</ul></flux:callout.text></flux:callout>@endif
                    <div class="mt-6"><flux:heading size="sm">Plan zmian</flux:heading>@php($sets = collect($variant['plan']['slots'])->flatMap(fn ($slot) => $slot['sets'])->groupBy('set_number')->sortKeys())<div class="mt-3 grid gap-3 md:grid-cols-2">@forelse ($sets as $number => $rules)<div class="rounded-2xl border border-zinc-200 p-4 dark:border-zinc-700"><flux:text class="font-medium">Set {{ $number }}</flux:text><ul class="mt-2 space-y-1 text-sm text-zinc-600 dark:text-zinc-300">@foreach ($rules as $rule)<li>{{ $rule['description'] }}</li>@endforeach</ul></div>@empty<flux:text class="text-sm text-zinc-600 dark:text-zinc-300">Plan nie wymaga zmian w trakcie meczu.</flux:text>@endforelse</div></div>
                    <div class="mt-6 overflow-x-auto"><flux:heading size="sm">Efekt treningowy</flux:heading><table class="mt-3 min-w-full text-left text-sm"><thead><tr class="border-b border-zinc-200 text-zinc-500 dark:border-zinc-700"><th class="p-2">Zawodnik</th><th class="p-2">Pozycja</th><th class="p-2">Start</th><th class="p-2">Akcje</th><th class="p-2">Zysk</th><th class="p-2">Koniec</th></tr></thead><tbody>@foreach ($variant['player_results'] as $result)<tr wire:key="result-{{ $variant['variant_key'] }}-{{ $result['id'] }}" class="border-b border-zinc-100 dark:border-zinc-800"><td class="p-2">{{ $result['name'] }}</td><td class="p-2">{{ $result['position_label'] }}</td><td class="p-2">{{ $result['starting_training_bar'] }}%</td><td class="p-2">{{ $result['played_actions'] }}</td><td class="p-2">+{{ $result['gained_training'] }}%</td><td class="p-2">{{ $result['final_training_bar'] }}%</td></tr>@endforeach</tbody></table></div>
                </section>
            @endif
        @endif
    @endif
    <flux:modal wire:model="confirmingVariantApplication"><flux:heading size="lg">Wyślij wariant do VM Managera</flux:heading>@php($pending = $this->pendingVariant) @if ($pending)<flux:text class="mt-2">Wariant #{{ $pending['rank'] }} · {{ $this->tacticsMatchTypeOptions[$tacticsMatchType] ?? $tacticsMatchType }}</flux:text><ul class="mt-4 text-sm">@foreach ($pending['lineup'] as $slot)<li wire:key="pending-{{ $slot['key'] }}">{{ $slot['label'] ?? $slot['key'] }}: {{ $slot['player']?->name ?? 'Brak' }}</li>@endforeach</ul><flux:text class="mt-4">Ławka: {{ count(collect($pending['bench'])->filter()) }} · Reguły VM: {{ $pending['substitution_rules_count'] ?? 'nieprawidłowe' }} · Zdarzenia w setach: {{ $pending['substitutions_count'] }}</flux:text>@endif<div class="mt-6 flex justify-end gap-3"><flux:button variant="ghost" wire:click="cancelApplyVariant">Anuluj</flux:button><flux:button variant="primary" wire:click="confirmApplyVariant">Wyślij ten wariant do VM Managera</flux:button></div></flux:modal>
    <flux:modal wire:model="confirmingChangesDeletion"><flux:heading size="lg">Usunąć wszystkie zmiany?</flux:heading><div class="mt-6 flex justify-end gap-3"><flux:button variant="ghost" wire:click="cancelDeleteAllChanges">Anuluj</flux:button><flux:button variant="danger" wire:click="deleteAllChanges">Usuń zmiany</flux:button></div></flux:modal>
</div>
