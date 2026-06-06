<?php

use App\Enums\PlayerPosition;
use App\MatchScenario;
use App\Models\Player;
use App\ScenarioSet;
use App\SubstitutionPlanGenerator;
use App\TrainingGainCalculator;
use App\LineupRecommendationService;
use App\TrainingOptimizerService;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Wynik optymalizacji')] class extends Component
{
    public array $optimizerInput = [];

    public function mount(): void
    {
        $this->optimizerInput = session('optimizer.input', []);
    }

    #[Computed]
    public function hasOptimizerInput(): bool
    {
        return $this->optimizerInput !== [];
    }

    #[Computed]
    public function scenariosCount(): int
    {
        return count($this->optimizerInput['scenarios'] ?? []);
    }

    #[Computed]
    public function totalActions(): int
    {
        return (int) collect($this->optimizerInput['scenarios'] ?? [])->sum('total_actions');
    }

    #[Computed]
    public function fairnessThreshold(): int
    {
        return max(0, min(100, (int) ($this->optimizerInput['fairness_threshold'] ?? 20)));
    }

    #[Computed]
    public function scenarioSafetyMode(): bool
    {
        return (bool) ($this->optimizerInput['scenario_safety_mode'] ?? false);
    }

    /**
     * @return array<int, string>
     */
    #[Computed]
    public function scenarioLabels(): array
    {
        return collect($this->optimizerInput['scenarios'] ?? [])
            ->pluck('label')
            ->values()
            ->all();
    }

    #[Computed]
    public function scenarioModels(): array
    {
        try {
            return collect($this->optimizerInput['scenarios'] ?? [])
                ->filter(fn (array $scenario): bool => is_string($scenario['input'] ?? null) && $scenario['input'] !== '')
                ->map(fn (array $scenario): MatchScenario => MatchScenario::fromInput(
                    $scenario['input'],
                    $scenario['label'] ?? 'Scenariusz',
                ))
                ->values()
                ->all();
        } catch (\InvalidArgumentException) {
            return [];
        }
    }

    #[Computed]
    public function scenarioSet(): ?ScenarioSet
    {
        $scenarios = $this->scenarioModels;

        return $scenarios === [] ? null : new ScenarioSet($scenarios);
    }

    #[Computed]
    public function rankingScenario(): ?MatchScenario
    {
        $scenarios = $this->scenarioModels;

        if ($scenarios === []) {
            return null;
        }

        $rankingScenario = $scenarios[0];

        foreach ($scenarios as $scenario) {
            if ($scenario->setsCount() > $rankingScenario->setsCount()) {
                $rankingScenario = $scenario;

                continue;
            }

            if ($scenario->setsCount() === $rankingScenario->setsCount() && $scenario->totalActions() > $rankingScenario->totalActions()) {
                $rankingScenario = $scenario;
            }
        }

        return $rankingScenario;
    }

    /**
     * @return array<int, array{slot_number: int, position: PlayerPosition, reserve_limit: int, players: array<int, Player>}>
     */
    #[Computed]
    public function slotDefinitions(): array
    {
        $positionValues = collect($this->optimizerInput['positions'] ?? [])
            ->pluck('value')
            ->unique()
            ->values();
        $reserveLimits = collect($this->optimizerInput['reserve_pools'] ?? [])
            ->mapWithKeys(fn (array $pool): array => [$pool['position'] => (int) $pool['reserve_limit']]);

        $playersByPosition = Player::query()
            ->active()
            ->whereIn('position', $positionValues->all())
            ->orderBy('training_bar')
            ->orderBy('name')
            ->get()
            ->groupBy(fn (Player $player): string => $player->position->value);

        return collect($this->optimizerInput['positions'] ?? [])
            ->values()
            ->map(function (array $position, int $index) use ($playersByPosition, $reserveLimits): array {
                $positionEnum = PlayerPosition::from($position['value']);

                return [
                    'slot_number' => $index + 1,
                    'position' => $positionEnum,
                    'reserve_limit' => (int) ($reserveLimits[$positionEnum->value] ?? 0),
                    'players' => ($playersByPosition[$positionEnum->value] ?? collect())
                        ->values()
                        ->all(),
                ];
            })
            ->all();
    }

    #[Computed]
    public function canBuildRanking(): bool
    {
        return $this->hasOptimizerInput && $this->scenarioSet !== null && $this->rankingScenario !== null;
    }

    /**
     * @return array<int, array{
     *     total_gained_training: int,
     *     final_training_bar_sum: int,
     *     players_below_fairness_threshold: int,
     *     lowest_final_training_bar: int,
     *     wasted_actions: int,
     *     substitutions_count: int,
     *     scenario_count: int,
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
    #[Computed]
    public function rankedPlans(): array
    {
        if (! $this->canBuildRanking || $this->scenarioSet === null) {
            return [];
        }

        return (new TrainingOptimizerService(
            new TrainingGainCalculator(),
            new SubstitutionPlanGenerator(),
        ))->optimizeForScenarioSet(
            slotDefinitions: $this->slotDefinitions,
            scenarioSet: $this->scenarioSet,
            limit: 5,
            fairnessThreshold: $this->fairnessThreshold,
            safeMode: $this->scenarioSafetyMode,
        );
    }

    #[Computed]
    public function hasRankedPlans(): bool
    {
        return $this->rankedPlans !== [];
    }

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
    #[Computed]
    public function lineupRecommendations(): array
    {
        return (new LineupRecommendationService)->recommend();
    }

    #[Computed]
    public function hasLineupRecommendations(): bool
    {
        return $this->lineupRecommendations['recommendations'] !== [];
    }
};
?>

<div class="flex h-full w-full flex-1 flex-col gap-6 p-4 md:p-6">
    <section class="flex flex-col gap-4 rounded-3xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-700 dark:bg-zinc-900 lg:flex-row lg:items-end lg:justify-between">
        <div class="space-y-2">
            <flux:heading size="xl" level="1">Wynik optymalizacji</flux:heading>
            <flux:text class="max-w-2xl text-zinc-600 dark:text-zinc-300">
                Ten ekran pokazuje podsumowanie wejścia z formularza. W kolejnym kroku w to miejsce wejdą starterzy, zmiennicy i ranking wariantów policzony przez silnik domenowy.
            </flux:text>
        </div>

        <div class="flex gap-3">
            <flux:button variant="primary" :href="route('optimizer.create')" wire:navigate>
                Wróć do formularza
            </flux:button>
            <flux:button variant="ghost" :href="route('dashboard')" wire:navigate>
                Wróć na dashboard
            </flux:button>
        </div>
    </section>

    <section class="rounded-3xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
        <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
            <div>
                <flux:heading size="lg">Propozycja składu</flux:heading>
                <flux:text class="mt-1 max-w-2xl text-sm text-zinc-600 dark:text-zinc-300">
                    Rekomendacja startowa na podstawie najniższych pasków treningowych w bazie. Do {{ \App\LineupRecommendationService::ALTERNATIVE_COUNT }} alternatyw — każda to pełny skład z kilkoma zmianami (zawodnik &lt;60% lub zbliżone paski ≥60%, ±{{ \App\LineupRecommendationService::ALTERNATIVE_SIMILAR_BAR_TOLERANCE }} p.p.).
                </flux:text>
            </div>
            @if (! $this->lineupRecommendations['is_complete'])
                <flux:button variant="primary" :href="route('players.index')" wire:navigate>
                    Zarządzaj zawodnikami
                </flux:button>
            @endif
        </div>

        @if (! $this->hasLineupRecommendations)
            <flux:callout class="mt-6" icon="users" color="amber">
                <flux:callout.heading>Brak aktywnych zawodników</flux:callout.heading>
                <flux:callout.text>
                    Dodaj aktywnych zawodników w bazie, aby zobaczyć propozycję składu.
                </flux:callout.text>
            </flux:callout>
        @else
            @if ($this->lineupRecommendations['missing_slots'] !== [])
                <flux:callout class="mt-6" icon="exclamation-triangle" color="amber">
                    <flux:callout.heading>Niekompletny skład</flux:callout.heading>
                    <flux:callout.text>
                        <ul class="mt-2 list-disc space-y-1 pl-5">
                            @foreach ($this->lineupRecommendations['missing_slots'] as $missingSlot)
                                <li wire:key="missing-slot-{{ $missingSlot['slot'] }}">
                                    Brak: {{ $missingSlot['label'] }} (dostępnych {{ $missingSlot['available'] }} / {{ $missingSlot['required'] }} na pozycji)
                                </li>
                            @endforeach
                        </ul>
                    </flux:callout.text>
                </flux:callout>
            @endif

            @php
                $lineupPrimary = collect($this->lineupRecommendations['recommendations'])->firstWhere('kind', 'primary');
                $lineupAlternatives = collect($this->lineupRecommendations['recommendations'])->where('kind', 'alternative')->values();
            @endphp

            @if ($lineupPrimary !== null)
                <div class="mt-6 max-w-md rounded-2xl border border-zinc-200 bg-zinc-50/70 p-4 dark:border-zinc-700 dark:bg-zinc-800/60">
                    <div class="flex items-center justify-between gap-3">
                        <flux:text class="font-medium text-zinc-950 dark:text-zinc-50">Skład główny</flux:text>
                        <flux:badge color="emerald">Rekomendowany</flux:badge>
                    </div>

                    <div class="mt-4 grid grid-cols-3 gap-3">
                        @foreach ($lineupPrimary['slots'] as $slot)
                            <div
                                wire:key="lineup-primary-{{ $slot['key'] }}"
                                @class([
                                    'rounded-xl border p-3 text-center',
                                    'border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900' => $slot['player'] !== null,
                                    'border-dashed border-amber-300 bg-amber-50/50 dark:border-amber-700 dark:bg-amber-950/20' => $slot['player'] === null,
                                    'col-start-2' => $slot['grid_column'] === 2 && $slot['grid_row'] === 3,
                                ])
                                style="grid-row: {{ $slot['grid_row'] }}; grid-column: {{ $slot['grid_column'] }};"
                            >
                                <flux:text class="text-xs uppercase tracking-[0.2em] text-zinc-500 dark:text-zinc-400">{{ $slot['abbreviation'] }}</flux:text>
                                <flux:text class="mt-1 text-sm font-medium text-zinc-950 dark:text-zinc-50">{{ $slot['player']?->name ?? '—' }}</flux:text>
                                @if ($slot['player'] !== null)
                                    <flux:badge class="mt-2" color="sky">{{ $slot['training_bar'] }}%</flux:badge>
                                @else
                                    <flux:badge class="mt-2" color="amber">Brak</flux:badge>
                                @endif
                                <flux:text class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">{{ $slot['label'] }}</flux:text>
                            </div>
                        @endforeach
                    </div>

                    <flux:text class="mt-4 text-sm font-medium text-zinc-900 dark:text-zinc-100">
                        Suma pasków: {{ $lineupPrimary['total_training_bar'] }}%
                    </flux:text>
                </div>
            @endif

            @if ($lineupAlternatives->isNotEmpty())
                <div class="mt-8">
                    <flux:heading size="md">Alternatywy ({{ $lineupAlternatives->count() }})</flux:heading>

                    <div class="mt-4 grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                        @foreach ($lineupAlternatives as $alternativeIndex => $recommendation)
                            <div wire:key="lineup-alternative-{{ $alternativeIndex }}" class="rounded-2xl border border-zinc-200 bg-zinc-50/70 p-4 dark:border-zinc-700 dark:bg-zinc-800/60">
                                <flux:text class="font-medium text-zinc-950 dark:text-zinc-50">Alternatywa {{ $alternativeIndex + 1 }}</flux:text>

                                @if ($recommendation['swap_description'] !== null)
                                    <flux:text class="mt-2 text-sm text-zinc-600 dark:text-zinc-300">{{ $recommendation['swap_description'] }}</flux:text>
                                @endif

                                <div class="mt-4 grid grid-cols-3 gap-3">
                                    @foreach ($recommendation['slots'] as $slot)
                                        <div
                                            wire:key="lineup-alt-{{ $alternativeIndex }}-{{ $slot['key'] }}"
                                            @class([
                                                'rounded-xl border p-3 text-center',
                                                'border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900' => $slot['player'] !== null,
                                                'border-dashed border-amber-300 bg-amber-50/50 dark:border-amber-700 dark:bg-amber-950/20' => $slot['player'] === null,
                                                'col-start-2' => $slot['grid_column'] === 2 && $slot['grid_row'] === 3,
                                                'ring-2 ring-emerald-500 dark:ring-emerald-400' => in_array($slot['key'], $recommendation['changed_slot_keys'] ?? [], true),
                                            ])
                                            style="grid-row: {{ $slot['grid_row'] }}; grid-column: {{ $slot['grid_column'] }};"
                                        >
                                            <flux:text class="text-xs uppercase tracking-[0.2em] text-zinc-500 dark:text-zinc-400">{{ $slot['abbreviation'] }}</flux:text>
                                            <flux:text class="mt-1 text-sm font-medium text-zinc-950 dark:text-zinc-50">{{ $slot['player']?->name ?? '—' }}</flux:text>
                                            @if ($slot['player'] !== null)
                                                <flux:badge class="mt-2" :color="($slot['training_bar'] ?? 0) < \App\LineupRecommendationService::ALTERNATIVE_SWAP_MAX_TRAINING_BAR ? 'emerald' : 'sky'">{{ $slot['training_bar'] }}%</flux:badge>
                                            @else
                                                <flux:badge class="mt-2" color="amber">Brak</flux:badge>
                                            @endif
                                            <flux:text class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">{{ $slot['label'] }}</flux:text>
                                        </div>
                                    @endforeach
                                </div>

                                <flux:text class="mt-4 text-sm font-medium text-zinc-900 dark:text-zinc-100">
                                    Suma pasków: {{ $recommendation['total_training_bar'] }}%
                                </flux:text>
                            </div>
                        @endforeach
                    </div>
                </div>
            @else
                <flux:callout class="mt-6" icon="information-circle" color="zinc">
                    <flux:callout.heading>Brak alternatyw</flux:callout.heading>
                    <flux:callout.text>
                        Nie znaleziono alternatywnych składów z co najmniej jedną sensowną zmianą względem składu głównego.
                    </flux:callout.text>
                </flux:callout>
            @endif

        @endif
    </section>

    @if (! $this->hasOptimizerInput)
        <section class="rounded-3xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
            <flux:callout icon="clipboard-document-list" color="amber">
                <flux:callout.heading>Brak danych wejściowych</flux:callout.heading>
                <flux:callout.text>
                    Najpierw uzupełnij formularz optymalizacji. Po zapisaniu wejścia zobaczysz tutaj wybrane pozycje i znormalizowane scenariusze meczu.
                </flux:callout.text>
            </flux:callout>
        </section>
    @else
        <section class="grid gap-4 md:grid-cols-3">
            <div class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                <flux:text class="text-sm uppercase tracking-[0.2em] text-zinc-500 dark:text-zinc-400">Pozycje</flux:text>
                <div class="mt-4 space-y-2">
                    @foreach ($optimizerInput['positions'] as $position)
                        <div wire:key="{{ $position['value'] }}" class="flex items-center justify-between gap-3">
                            <flux:text class="font-medium text-zinc-950 dark:text-zinc-50">{{ $position['label'] }}</flux:text>
                            <flux:badge :color="$position['active_players'] >= 2 ? 'emerald' : 'amber'">{{ $position['active_players'] }}</flux:badge>
                        </div>
                    @endforeach
                </div>
            </div>

            <div class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                <flux:text class="text-sm uppercase tracking-[0.2em] text-zinc-500 dark:text-zinc-400">Tryb wejścia</flux:text>
                <div class="mt-3 text-2xl font-semibold text-zinc-950 dark:text-zinc-50">{{ $optimizerInput['scenario_mode_label'] }}</div>
                <flux:text class="mt-3 text-sm text-zinc-600 dark:text-zinc-300">{{ $optimizerInput['scenario_source_label'] }}</flux:text>
                <flux:text class="mt-1 text-sm text-zinc-600 dark:text-zinc-300">
                    Tryb bezpieczeństwa: {{ $this->scenarioSafetyMode ? 'włączony' : 'wyłączony' }}
                </flux:text>
                <flux:text class="mt-3 text-sm text-zinc-600 dark:text-zinc-300">
                    Próg minimalnego paska: {{ $this->fairnessThreshold }}%
                </flux:text>
                <flux:text class="mt-1 text-sm text-zinc-600 dark:text-zinc-300">
                    Agregacja scenariuszy: {{ $this->scenariosCount }}
                </flux:text>
                <flux:text class="mt-1 text-sm text-zinc-600 dark:text-zinc-300">
                    Scenariusz referencyjny: {{ $this->rankingScenario?->label ?? 'Brak' }}
                </flux:text>
            </div>

            <div class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                <flux:text class="text-sm uppercase tracking-[0.2em] text-zinc-500 dark:text-zinc-400">Scenariusze</flux:text>
                <div class="mt-3 text-4xl font-semibold text-zinc-950 dark:text-zinc-50">{{ $this->scenariosCount }}</div>
                <flux:text class="mt-3 text-sm text-zinc-600 dark:text-zinc-300">Łączna liczba akcji wejściowych: {{ $this->totalActions }}</flux:text>
                @if ($this->scenarioLabels !== [])
                    <flux:text class="mt-1 text-sm text-zinc-600 dark:text-zinc-300">
                        Uwzględnione: {{ implode(', ', $this->scenarioLabels) }}
                    </flux:text>
                @endif
            </div>
        </section>

        <section class="rounded-3xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
            <div class="flex items-center justify-between gap-4">
                <div>
                    <flux:heading size="lg">Pule rezerwowych</flux:heading>
                    <flux:text class="mt-1 text-sm text-zinc-600 dark:text-zinc-300">
                        Ranking jest liczony tylko na zawodnikach mieszczących się w skonfigurowanej puli dla każdej pozycji.
                    </flux:text>
                </div>
                <flux:badge color="sky">{{ count($optimizerInput['reserve_pools'] ?? []) }} pule</flux:badge>
            </div>

            <div class="mt-6 grid gap-4 md:grid-cols-2">
                @foreach ($optimizerInput['reserve_pools'] ?? [] as $reservePool)
                    <div wire:key="reserve-pool-{{ $reservePool['position'] }}" class="rounded-2xl border border-zinc-200 bg-zinc-50/70 p-4 dark:border-zinc-700 dark:bg-zinc-800/60">
                        <flux:text class="font-medium text-zinc-950 dark:text-zinc-50">{{ $reservePool['position_label'] }}</flux:text>
                        <div class="mt-3 grid gap-3 sm:grid-cols-3">
                            <div>
                                <flux:text class="text-xs uppercase tracking-[0.2em] text-zinc-500 dark:text-zinc-400">Sloty</flux:text>
                                <flux:text class="mt-1 text-sm text-zinc-900 dark:text-zinc-100">{{ $reservePool['slot_count'] }}</flux:text>
                            </div>
                            <div>
                                <flux:text class="text-xs uppercase tracking-[0.2em] text-zinc-500 dark:text-zinc-400">Rezerwowi</flux:text>
                                <flux:text class="mt-1 text-sm text-zinc-900 dark:text-zinc-100">{{ $reservePool['reserve_limit'] }}</flux:text>
                            </div>
                            <div>
                                <flux:text class="text-xs uppercase tracking-[0.2em] text-zinc-500 dark:text-zinc-400">Kandydaci</flux:text>
                                <flux:text class="mt-1 text-sm text-zinc-900 dark:text-zinc-100">{{ $reservePool['candidate_limit'] }}</flux:text>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        </section>

        <section class="rounded-3xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
            <div class="flex items-center justify-between gap-4">
                <div>
                    <flux:heading size="lg">Znormalizowane scenariusze</flux:heading>
                    <flux:text class="mt-1 text-sm text-zinc-600 dark:text-zinc-300">To jest payload przygotowany pod przyszłe obliczenia i ranking wariantów.</flux:text>
                </div>
                <flux:badge color="sky">{{ $this->scenariosCount }} wpisy</flux:badge>
            </div>

            <div class="mt-6 space-y-4">
                @foreach ($optimizerInput['scenarios'] as $scenario)
                    <div wire:key="{{ $scenario['label'] }}" class="rounded-2xl border border-zinc-200 bg-zinc-50/70 p-4 dark:border-zinc-700 dark:bg-zinc-800/60">
                        <div class="flex flex-col gap-3 md:flex-row md:items-start md:justify-between">
                            <div>
                                <flux:text class="font-medium text-zinc-950 dark:text-zinc-50">{{ $scenario['label'] }}</flux:text>
                                <flux:text class="mt-1 text-sm text-zinc-600 dark:text-zinc-300">{{ $scenario['input'] }}</flux:text>
                            </div>
                            <div class="flex gap-2">
                                <flux:badge color="sky">{{ $scenario['sets_count'] }} sety</flux:badge>
                                <flux:badge color="emerald">{{ $scenario['total_actions'] }} akcji</flux:badge>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        </section>

        <section class="grid gap-6 xl:grid-cols-[0.95fr_1.05fr]">
            <div class="rounded-3xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                <div class="flex items-center justify-between gap-4">
                <div>
                    <flux:heading size="lg">Top warianty</flux:heading>
                    @if ($this->scenarioSafetyMode)
                        <flux:text class="mt-1 text-sm text-zinc-600 dark:text-zinc-300">
                            Ranking działa w trybie bezpiecznym. Najpierw liczy się najgorszy scenariusz z wybranego zakresu, potem liczba zawodników poniżej progu {{ $this->fairnessThreshold }}% i bardziej wyrównany rozkład pasków. Plan bazowy do generowania wariantów bierzemy ze scenariusza referencyjnego o największej liczbie setów.
                        </flux:text>
                    @else
                        <flux:text class="mt-1 text-sm text-zinc-600 dark:text-zinc-300">
                            Ranking jest agregowany po wszystkich scenariuszach z formularza. Najpierw liczy się suma przyrostu, potem liczba zawodników poniżej progu {{ $this->fairnessThreshold }}%, a potem bardziej wyrównany rozkład pasków. Plan bazowy do generowania wariantów bierzemy ze scenariusza referencyjnego o największej liczbie setów.
                        </flux:text>
                    @endif
                </div>
                <div class="flex gap-2">
                    @if ($this->rankingScenario !== null)
                        <flux:badge color="sky">{{ $this->rankingScenario->setsCount() }} sety</flux:badge>
                    @endif
                    @if ($this->scenarioSafetyMode)
                        <flux:badge color="amber">Bezpieczny</flux:badge>
                    @endif
                    @if ($this->hasRankedPlans)
                        <flux:badge color="emerald">{{ count($this->rankedPlans) }} wariantów</flux:badge>
                    @endif
                </div>
                </div>

                @if (! $this->hasRankedPlans)
                    <div class="mt-6">
                        <flux:callout icon="exclamation-triangle" color="amber">
                            <flux:callout.heading>Brak wariantów do policzenia</flux:callout.heading>
                            <flux:callout.text>
                                Dla jednej z wybranych pozycji brakuje aktywnych zawodników potrzebnych do zbudowania legalnych planów zmian.
                            </flux:callout.text>
                        </flux:callout>
                    </div>
                @else
                    <div class="mt-6 space-y-4">
                        @foreach ($this->rankedPlans as $index => $rankedPlan)
                            <div wire:key="ranked-plan-{{ $index }}" class="rounded-2xl border border-zinc-200 bg-zinc-50/70 p-4 dark:border-zinc-700 dark:bg-zinc-800/60">
                                <div class="flex flex-col gap-3 md:flex-row md:items-start md:justify-between">
                                    <div>
                                        <flux:text class="font-medium text-zinc-950 dark:text-zinc-50">Wariant {{ $index + 1 }}</flux:text>
                                        @php($worstCaseScenario = collect($rankedPlan['scenario_results'] ?? [])->firstWhere('is_worst_case', true))
                                        <flux:text class="mt-1 text-sm text-zinc-600 dark:text-zinc-300">
                                            @if ($this->scenarioSafetyMode && $worstCaseScenario !== null)
                                                Najgorszy scenariusz: {{ $worstCaseScenario['label'] }},
                                                przyrost: {{ $rankedPlan['total_gained_training'] }},
                                                suma końcowych pasków: {{ $rankedPlan['final_training_bar_sum'] }},
                                                poniżej progu: {{ $rankedPlan['players_below_fairness_threshold'] }},
                                                najniższy pasek: {{ $rankedPlan['lowest_final_training_bar'] }}%,
                                                zmarnowane akcje: {{ $rankedPlan['wasted_actions'] }},
                                                liczba zmian: {{ $rankedPlan['substitutions_count'] }}
                                            @else
                                                Scenariusze: {{ $rankedPlan['scenario_count'] }},
                                                Łączny przyrost: {{ $rankedPlan['total_gained_training'] }},
                                                suma końcowych pasków: {{ $rankedPlan['final_training_bar_sum'] }},
                                                poniżej progu: {{ $rankedPlan['players_below_fairness_threshold'] }},
                                                najniższy pasek: {{ $rankedPlan['lowest_final_training_bar'] }}%,
                                                zmarnowane akcje: {{ $rankedPlan['wasted_actions'] }},
                                                liczba zmian: {{ $rankedPlan['substitutions_count'] }}
                                            @endif
                                        </flux:text>
                                    </div>
                                    @if ($index === 0)
                                        <flux:badge color="emerald">Najlepszy</flux:badge>
                                    @endif
                                </div>

                                @php($scenarioResults = $rankedPlan['scenario_results'] ?? [])

                                <div class="mt-4 space-y-3">
                                    @if (count($scenarioResults) > 1)
                                        @foreach ($scenarioResults as $scenarioResult)
                                            <details wire:key="ranked-plan-{{ $index }}-scenario-{{ $loop->index }}" class="rounded-2xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900" @if ($scenarioResult['is_worst_case']) open @endif>
                                                <summary class="flex cursor-pointer list-none items-center justify-between gap-4">
                                                    <div>
                                                        <flux:text class="font-medium text-zinc-950 dark:text-zinc-50">
                                                            {{ $scenarioResult['label'] }}
                                                        </flux:text>
                                                        <flux:text class="mt-1 text-sm text-zinc-600 dark:text-zinc-300">
                                                            {{ $scenarioResult['input'] }}
                                                        </flux:text>
                                                    </div>
                                                    <div class="flex flex-wrap justify-end gap-2">
                                                        <flux:badge color="sky">{{ $scenarioResult['sets_count'] }} sety</flux:badge>
                                                        <flux:badge color="emerald">+{{ $scenarioResult['total_gained_training'] }}%</flux:badge>
                                                        <flux:badge color="zinc">{{ $scenarioResult['lowest_final_training_bar'] }}% min</flux:badge>
                                                        @if ($scenarioResult['is_worst_case'])
                                                            <flux:badge color="rose">Najgorszy</flux:badge>
                                                        @endif
                                                    </div>
                                                </summary>

                                                <div class="mt-4 space-y-3">
                                                    @foreach ($scenarioResult['plan']['slots'] as $slot)
                                                        <div wire:key="ranked-plan-{{ $index }}-scenario-{{ $loop->parent->index }}-slot-{{ $slot['slot_number'] }}" class="rounded-2xl border border-zinc-200 bg-zinc-50/70 p-4 dark:border-zinc-700 dark:bg-zinc-800/60">
                                                            <div class="flex items-center justify-between gap-4">
                                                                <div>
                                                                    <flux:text class="font-medium text-zinc-950 dark:text-zinc-50">
                                                                        Slot {{ $slot['slot_number'] }} · {{ $slot['position_label'] }}
                                                                    </flux:text>
                                                                    <flux:text class="mt-1 text-sm text-zinc-600 dark:text-zinc-300">
                                                                        Starter: {{ $slot['starter']['name'] }}
                                                                    </flux:text>
                                                                </div>
                                                                <flux:badge color="sky">{{ count($slot['sets']) }} sety</flux:badge>
                                                            </div>

                                                            <div class="mt-4 space-y-2">
                                                                @foreach ($slot['sets'] as $set)
                                                                    <flux:text wire:key="ranked-plan-{{ $index }}-scenario-{{ $loop->parent->parent->index }}-slot-{{ $slot['slot_number'] }}-set-{{ $set['set_number'] }}" class="text-sm text-zinc-700 dark:text-zinc-200">
                                                                        {{ $set['description'] }}
                                                                    </flux:text>
                                                                @endforeach
                                                            </div>
                                                        </div>
                                                    @endforeach
                                                </div>
                                            </details>
                                        @endforeach
                                    @else
                                        @foreach ($rankedPlan['plan']['slots'] as $slot)
                                            <div wire:key="ranked-plan-{{ $index }}-slot-{{ $slot['slot_number'] }}" class="rounded-2xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
                                                <div class="flex items-center justify-between gap-4">
                                                    <div>
                                                        <flux:text class="font-medium text-zinc-950 dark:text-zinc-50">
                                                            Slot {{ $slot['slot_number'] }} · {{ $slot['position_label'] }}
                                                        </flux:text>
                                                        <flux:text class="mt-1 text-sm text-zinc-600 dark:text-zinc-300">
                                                            Starter: {{ $slot['starter']['name'] }}
                                                        </flux:text>
                                                    </div>
                                                    <flux:badge color="sky">{{ count($slot['sets']) }} sety</flux:badge>
                                                </div>

                                                <div class="mt-4 space-y-2">
                                                    @foreach ($slot['sets'] as $set)
                                                        <flux:text wire:key="ranked-plan-{{ $index }}-slot-{{ $slot['slot_number'] }}-set-{{ $set['set_number'] }}" class="text-sm text-zinc-700 dark:text-zinc-200">
                                                            {{ $set['description'] }}
                                                        </flux:text>
                                                    @endforeach
                                                </div>
                                            </div>
                                        @endforeach
                                    @endif
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>

            <div class="rounded-3xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                <flux:heading size="lg">Szczegóły najlepszego wariantu</flux:heading>

                @if (! $this->hasRankedPlans)
                    <flux:text class="mt-4 text-sm text-zinc-600 dark:text-zinc-300">
                        Gdy tylko da się policzyć ranking, tutaj pojawi się rozkład akcji i końcowych pasków dla zawodników.
                    </flux:text>
                @else
                    @php($bestPlan = $this->rankedPlans[0])

                    @if (count($bestPlan['scenario_results'] ?? []) > 1)
                        <flux:text class="mt-2 text-sm text-zinc-600 dark:text-zinc-300">
                            Po prawej pokazuję podsumowanie najgorszego scenariusza dla najlepszego wariantu. Pełny rozkład per scenariusz jest po lewej.
                        </flux:text>
                    @endif

                    <div class="mt-6 grid gap-3">
                        @foreach ($bestPlan['player_results'] as $playerResult)
                            <div wire:key="best-player-{{ $playerResult['id'] }}" class="rounded-2xl border border-zinc-200 bg-zinc-50/70 p-4 dark:border-zinc-700 dark:bg-zinc-800/60">
                                <div class="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
                                    <div>
                                        <flux:text class="font-medium text-zinc-950 dark:text-zinc-50">{{ $playerResult['name'] }}</flux:text>
                                        <flux:text class="mt-1 text-sm text-zinc-600 dark:text-zinc-300">{{ $playerResult['position_label'] }}</flux:text>
                                    </div>
                                    <div class="flex gap-2">
                                        <flux:badge color="sky">{{ $playerResult['played_actions'] }} akcji</flux:badge>
                                        <flux:badge color="emerald">+{{ $playerResult['gained_training'] }}%</flux:badge>
                                    </div>
                                </div>

                                <div class="mt-4 grid gap-3 sm:grid-cols-4">
                                    <div>
                                        <flux:text class="text-xs uppercase tracking-[0.2em] text-zinc-500 dark:text-zinc-400">Start</flux:text>
                                        <flux:text class="mt-1 text-sm text-zinc-900 dark:text-zinc-100">{{ $playerResult['starting_training_bar'] }}%</flux:text>
                                    </div>
                                    <div>
                                        <flux:text class="text-xs uppercase tracking-[0.2em] text-zinc-500 dark:text-zinc-400">Koniec</flux:text>
                                        <flux:text class="mt-1 text-sm text-zinc-900 dark:text-zinc-100">{{ $playerResult['final_training_bar'] }}%</flux:text>
                                    </div>
                                    <div>
                                        <flux:text class="text-xs uppercase tracking-[0.2em] text-zinc-500 dark:text-zinc-400">Zysk</flux:text>
                                        <flux:text class="mt-1 text-sm text-zinc-900 dark:text-zinc-100">{{ $playerResult['gained_training'] }}%</flux:text>
                                    </div>
                                    <div>
                                        <flux:text class="text-xs uppercase tracking-[0.2em] text-zinc-500 dark:text-zinc-400">Strata</flux:text>
                                        <flux:text class="mt-1 text-sm text-zinc-900 dark:text-zinc-100">{{ $playerResult['wasted_actions'] }} akcji</flux:text>
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        </section>
    @endif
</div>
