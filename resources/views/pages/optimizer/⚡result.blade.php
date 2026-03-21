<?php

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
    @endif
</div>
