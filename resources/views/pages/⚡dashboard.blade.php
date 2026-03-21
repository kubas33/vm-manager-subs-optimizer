<?php

use App\Enums\PlayerPosition;
use App\Models\Player;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Dashboard')] class extends Component
{
    #[Computed]
    public function activePlayersCount(): int
    {
        return Player::query()->active()->count();
    }

    #[Computed]
    public function inactivePlayersCount(): int
    {
        return Player::query()->where('active', false)->count();
    }

    #[Computed]
    public function positionCoverage(): array
    {
        $counts = Player::query()
            ->selectRaw('position, count(*) as aggregate')
            ->groupBy('position')
            ->pluck('aggregate', 'position');

        return collect(PlayerPosition::cases())
            ->map(fn (PlayerPosition $position) => [
                'label' => $position->label(),
                'count' => (int) ($counts[$position->value] ?? 0),
            ])
            ->all();
    }
};
?>

<div class="flex h-full w-full flex-1 flex-col gap-8 p-4 md:p-6">
    <section class="relative overflow-hidden rounded-3xl border border-zinc-200/80 bg-linear-to-br from-white via-zinc-50 to-emerald-50/70 p-6 shadow-sm dark:border-zinc-700 dark:from-zinc-900 dark:via-zinc-900 dark:to-emerald-950/40">
        <div class="absolute -top-10 right-0 h-32 w-32 rounded-full bg-emerald-200/60 blur-3xl dark:bg-emerald-500/10"></div>

        <div class="relative flex flex-col gap-6 lg:flex-row lg:items-end lg:justify-between">
            <div class="max-w-2xl space-y-3">
                <flux:badge color="emerald">MVP foundation</flux:badge>
                <flux:heading size="xl" level="1">VM Manager Subs Optimizer</flux:heading>
                <flux:text class="max-w-xl text-base text-zinc-600 dark:text-zinc-300">
                    Bazowy szkielet aplikacji jest gotowy. Kolejne kroki to pełny ekran zawodników, formularz optymalizacji i silnik liczenia wariantów zmian.
                </flux:text>
            </div>

            <div class="flex flex-col gap-3 sm:flex-row">
                <flux:button variant="primary" :href="route('players.index')" wire:navigate>
                    Przejdź do zawodników
                </flux:button>
                <flux:button variant="ghost" :href="route('optimizer.create')" wire:navigate>
                    Otwórz optymalizację
                </flux:button>
            </div>
        </div>
    </section>

    <section class="grid gap-4 md:grid-cols-3">
        <div class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
            <flux:text class="text-sm uppercase tracking-[0.2em] text-zinc-500 dark:text-zinc-400">Aktywni zawodnicy</flux:text>
            <div class="mt-3 text-4xl font-semibold text-zinc-950 dark:text-zinc-50">{{ $this->activePlayersCount }}</div>
            <flux:text class="mt-3 text-sm text-zinc-600 dark:text-zinc-300">To główna pula uwzględniana przy optymalizacji składu.</flux:text>
        </div>

        <div class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
            <flux:text class="text-sm uppercase tracking-[0.2em] text-zinc-500 dark:text-zinc-400">Nieaktywni zawodnicy</flux:text>
            <div class="mt-3 text-4xl font-semibold text-zinc-950 dark:text-zinc-50">{{ $this->inactivePlayersCount }}</div>
            <flux:text class="mt-3 text-sm text-zinc-600 dark:text-zinc-300">Przyda się do czasowego ukrywania zawodników poza analizą.</flux:text>
        </div>

        <div class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
            <flux:text class="text-sm uppercase tracking-[0.2em] text-zinc-500 dark:text-zinc-400">Stan MVP</flux:text>
            <div class="mt-3 text-2xl font-semibold text-zinc-950 dark:text-zinc-50">Routing i domena</div>
            <flux:text class="mt-3 text-sm text-zinc-600 dark:text-zinc-300">Gotowe: Sail, model `Player`, seed danych, strony bazowe i nawigacja.</flux:text>
        </div>
    </section>

    <section class="grid gap-4 xl:grid-cols-[1.2fr_0.8fr]">
        <div class="rounded-3xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
            <div class="flex items-center justify-between gap-4">
                <div>
                    <flux:heading size="lg">Pokrycie pozycji</flux:heading>
                    <flux:text class="mt-1 text-sm text-zinc-600 dark:text-zinc-300">Szybki przegląd, czy każda pozycja ma zawodników gotowych do analizy.</flux:text>
                </div>
                <flux:badge color="sky">{{ count($this->positionCoverage) }} pozycji</flux:badge>
            </div>

            <div class="mt-6 grid gap-3 sm:grid-cols-2">
                @foreach ($this->positionCoverage as $coverage)
                    <div wire:key="{{ $coverage['label'] }}" class="rounded-2xl border border-zinc-200/80 bg-zinc-50/70 p-4 dark:border-zinc-700 dark:bg-zinc-800/70">
                        <div class="flex items-center justify-between gap-4">
                            <flux:text class="font-medium text-zinc-900 dark:text-zinc-100">{{ $coverage['label'] }}</flux:text>
                            <flux:badge :color="$coverage['count'] > 0 ? 'emerald' : 'rose'">{{ $coverage['count'] }}</flux:badge>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>

        <div class="rounded-3xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
            <flux:heading size="lg">Co dalej</flux:heading>
            <div class="mt-4 space-y-4">
                <div class="rounded-2xl border border-zinc-200/80 p-4 dark:border-zinc-700">
                    <flux:text class="font-medium text-zinc-900 dark:text-zinc-100">1. Lista zawodników</flux:text>
                    <flux:text class="mt-2 text-sm text-zinc-600 dark:text-zinc-300">Widok z filtrowaniem, formularzem dodawania i edycją pasków treningowych.</flux:text>
                </div>
                <div class="rounded-2xl border border-zinc-200/80 p-4 dark:border-zinc-700">
                    <flux:text class="font-medium text-zinc-900 dark:text-zinc-100">2. Formularz optymalizacji</flux:text>
                    <flux:text class="mt-2 text-sm text-zinc-600 dark:text-zinc-300">Wybór dwóch pozycji i scenariusza meczu jako wejście dla silnika.</flux:text>
                </div>
                <div class="rounded-2xl border border-zinc-200/80 p-4 dark:border-zinc-700">
                    <flux:text class="font-medium text-zinc-900 dark:text-zinc-100">3. Ranking wariantów</flux:text>
                    <flux:text class="mt-2 text-sm text-zinc-600 dark:text-zinc-300">Ocena planów zmian pod maksymalny przyrost paska i minimalne straty.</flux:text>
                </div>
            </div>
        </div>
    </section>
</div>
