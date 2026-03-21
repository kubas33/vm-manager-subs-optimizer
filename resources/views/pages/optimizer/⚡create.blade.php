<?php

use App\Enums\PlayerPosition;
use App\Models\Player;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Optymalizacja')] class extends Component
{
    #[Computed]
    public function positions(): array
    {
        return PlayerPosition::options();
    }

    #[Computed]
    public function activePlayersCount(): int
    {
        return Player::query()->active()->count();
    }
};
?>

<div class="flex h-full w-full flex-1 flex-col gap-6 p-4 md:p-6">
    <section class="rounded-3xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
        <flux:heading size="xl" level="1">Optymalizacja skladu</flux:heading>
        <flux:text class="mt-3 max-w-2xl text-zinc-600 dark:text-zinc-300">
            Trasa i baza pod formularz juz sa gotowe. W nastepnym tasku ten ekran dostanie pola wyboru dwoch pozycji, tryb scenariusza i walidacje wejscia.
        </flux:text>

        <div class="mt-6 flex flex-wrap gap-3">
            <flux:button variant="primary" :href="route('players.index')" wire:navigate>
                Przygotuj zawodnikow
            </flux:button>
            <flux:button variant="ghost" :href="route('optimizer.result')" wire:navigate>
                Zobacz placeholder wyniku
            </flux:button>
        </div>
    </section>

    <section class="grid gap-4 lg:grid-cols-[1.1fr_0.9fr]">
        <div class="rounded-3xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
            <flux:heading size="lg">Docelowe wejscie formularza</flux:heading>
            <div class="mt-5 grid gap-3 sm:grid-cols-2">
                @foreach ($this->positions as $value => $label)
                    <div wire:key="{{ $value }}" class="rounded-2xl border border-zinc-200 bg-zinc-50/70 p-4 dark:border-zinc-700 dark:bg-zinc-800/60">
                        <flux:text class="font-medium text-zinc-900 dark:text-zinc-100">{{ $label }}</flux:text>
                        <flux:text class="mt-2 text-sm text-zinc-600 dark:text-zinc-300">Pozycja dostepna do wyboru w MVP.</flux:text>
                    </div>
                @endforeach
            </div>
        </div>

        <div class="rounded-3xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
            <flux:heading size="lg">Gotowosc danych</flux:heading>
            <div class="mt-5 rounded-2xl border border-emerald-200 bg-emerald-50/80 p-4 dark:border-emerald-900 dark:bg-emerald-950/30">
                <flux:text class="font-medium text-emerald-900 dark:text-emerald-200">
                    {{ $this->activePlayersCount }} aktywnych zawodnikow jest juz dostepnych w bazie.
                </flux:text>
                <flux:text class="mt-2 text-sm text-emerald-800 dark:text-emerald-300">
                    To wystarcza do budowy pierwszej wersji formularza i testowania przeplywu nawigacji.
                </flux:text>
            </div>
        </div>
    </section>
</div>
