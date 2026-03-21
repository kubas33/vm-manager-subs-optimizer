<?php

use App\Models\Player;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Zawodnicy')] class extends Component
{
    #[Computed]
    public function players()
    {
        return Player::query()
            ->orderBy('position')
            ->orderBy('name')
            ->get();
    }

    #[Computed]
    public function activePlayersCount(): int
    {
        return Player::query()->active()->count();
    }
};
?>

<div class="flex h-full w-full flex-1 flex-col gap-6 p-4 md:p-6">
    <section class="flex flex-col gap-4 rounded-3xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-700 dark:bg-zinc-900 lg:flex-row lg:items-end lg:justify-between">
        <div class="space-y-2">
            <flux:heading size="xl" level="1">Zawodnicy</flux:heading>
            <flux:text class="max-w-2xl text-zinc-600 dark:text-zinc-300">
                Bazowa lista danych jest juz podlaczona do domeny `Player`. W kolejnym tasku ten ekran dostanie filtry, formularz dodawania i edycje.
            </flux:text>
        </div>

        <div class="flex items-center gap-3">
            <flux:badge color="emerald">{{ $this->activePlayersCount }} aktywnych</flux:badge>
            <flux:button variant="ghost" :href="route('optimizer.create')" wire:navigate>
                Przejdz do optymalizacji
            </flux:button>
        </div>
    </section>

    <section class="overflow-hidden rounded-3xl border border-zinc-200 bg-white shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
        <div class="border-b border-zinc-200 px-6 py-4 dark:border-zinc-700">
            <flux:heading size="lg">Aktualna pula zawodnikow</flux:heading>
        </div>

        <div class="divide-y divide-zinc-200 dark:divide-zinc-700">
            @foreach ($this->players as $player)
                <div wire:key="{{ $player->id }}" class="grid gap-4 px-6 py-4 md:grid-cols-[1.2fr_0.8fr_120px_110px] md:items-center">
                    <div>
                        <flux:text class="font-medium text-zinc-950 dark:text-zinc-50">{{ $player->name }}</flux:text>
                    </div>
                    <div>
                        <flux:badge color="sky">{{ $player->position->label() }}</flux:badge>
                    </div>
                    <div>
                        <flux:text class="text-sm text-zinc-600 dark:text-zinc-300">{{ $player->training_bar }}%</flux:text>
                    </div>
                    <div>
                        <flux:badge :color="$player->active ? 'emerald' : 'amber'">
                            {{ $player->active ? 'aktywny' : 'nieaktywny' }}
                        </flux:badge>
                    </div>
                </div>
            @endforeach
        </div>
    </section>
</div>
