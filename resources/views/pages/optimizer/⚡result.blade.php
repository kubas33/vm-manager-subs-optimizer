<?php

use App\Enums\PlayerPosition;
use App\MatchScenario;
use App\Models\Player;
use App\SubstitutionPlanGenerator;
use App\TrainingGainCalculator;
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
    public function previewScenario(): ?MatchScenario
    {
        $input = $this->optimizerInput['scenarios'][0]['input'] ?? null;
        $label = $this->optimizerInput['scenarios'][0]['label'] ?? 'Scenariusz 1';

        if (! is_string($input) || $input === '') {
            return null;
        }

        return MatchScenario::fromInput($input, $label);
    }

    /**
     * @return array<int, array{slot_number: int, position: PlayerPosition, players: array<int, Player>}>
     */
    #[Computed]
    public function slotDefinitions(): array
    {
        $positionValues = collect($this->optimizerInput['positions'] ?? [])
            ->pluck('value')
            ->unique()
            ->values();

        $playersByPosition = Player::query()
            ->active()
            ->whereIn('position', $positionValues->all())
            ->orderBy('training_bar')
            ->orderBy('name')
            ->get()
            ->groupBy(fn (Player $player): string => $player->position->value);

        return collect($this->optimizerInput['positions'] ?? [])
            ->values()
            ->map(function (array $position, int $index) use ($playersByPosition): array {
                $positionEnum = PlayerPosition::from($position['value']);

                return [
                    'slot_number' => $index + 1,
                    'position' => $positionEnum,
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
        return $this->hasOptimizerInput && $this->previewScenario !== null;
    }

    /**
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
    #[Computed]
    public function rankedPlans(): array
    {
        if (! $this->canBuildRanking || $this->previewScenario === null) {
            return [];
        }

        return (new TrainingOptimizerService(
            new TrainingGainCalculator(),
            new SubstitutionPlanGenerator(),
        ))->optimize($this->slotDefinitions, $this->previewScenario, 5);
    }

    #[Computed]
    public function hasRankedPlans(): bool
    {
        return $this->rankedPlans !== [];
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
            </div>

            <div class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                <flux:text class="text-sm uppercase tracking-[0.2em] text-zinc-500 dark:text-zinc-400">Scenariusze</flux:text>
                <div class="mt-3 text-4xl font-semibold text-zinc-950 dark:text-zinc-50">{{ $this->scenariosCount }}</div>
                <flux:text class="mt-3 text-sm text-zinc-600 dark:text-zinc-300">Łączna liczba akcji wejściowych: {{ $this->totalActions }}</flux:text>
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
                        <flux:text class="mt-1 text-sm text-zinc-600 dark:text-zinc-300">
                            Ranking jest liczony dla pierwszego scenariusza z formularza. Agregacja wielu scenariuszy będzie następnym krokiem, a w tej wersji bierzemy do 3 najlepszych kandydatów na każdą pozycję, żeby wynik liczył się szybko.
                        </flux:text>
                    </div>
                    @if ($this->previewScenario !== null)
                        <flux:badge color="sky">{{ $this->previewScenario->setsCount() }} sety</flux:badge>
                    @endif
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
                                        <flux:text class="mt-1 text-sm text-zinc-600 dark:text-zinc-300">
                                            Suma końcowych pasków: {{ $rankedPlan['final_training_bar_sum'] }},
                                            zmarnowane akcje: {{ $rankedPlan['wasted_actions'] }},
                                            liczba zmian: {{ $rankedPlan['substitutions_count'] }}
                                        </flux:text>
                                    </div>
                                    @if ($index === 0)
                                        <flux:badge color="emerald">Najlepszy</flux:badge>
                                    @endif
                                </div>

                                <div class="mt-4 space-y-3">
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
