<?php

use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Wynik optymalizacji')] class extends Component
{
    //
};
?>

<div class="flex h-full w-full flex-1 flex-col gap-6 p-4 md:p-6">
    <section class="rounded-3xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
        <flux:heading size="xl" level="1">Wynik optymalizacji</flux:heading>
        <flux:text class="mt-3 max-w-2xl text-zinc-600 dark:text-zinc-300">
            Ta strona jest placeholderem pod wynik silnika obliczeniowego. Po wdrożeniu formularza i serwisu optymalizującego pojawią się tu starterzy, zmiennicy i ranking wariantów.
        </flux:text>

        <div class="mt-6 flex gap-3">
            <flux:button variant="primary" :href="route('optimizer.create')" wire:navigate>
                Wróć do formularza
            </flux:button>
            <flux:button variant="ghost" :href="route('dashboard')" wire:navigate>
                Wróć na dashboard
            </flux:button>
        </div>
    </section>
</div>
