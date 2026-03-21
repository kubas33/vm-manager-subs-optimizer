<?php

use App\Enums\PlayerPosition;
use App\Models\Player;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Zawodnicy')] class extends Component
{
    public string $search = '';
    public string $filterPosition = '';
    public string $filterActive = 'all';

    public ?int $editingPlayerId = null;
    public string $name = '';
    public string $position = '';
    public int|string $trainingBar = 0;
    public bool $active = true;

    #[Computed]
    public function players()
    {
        return Player::query()
            ->when($this->search !== '', function ($query): void {
                $query->where('name', 'like', '%'.$this->search.'%');
            })
            ->when($this->filterPosition !== '', function ($query): void {
                $query->where('position', $this->filterPosition);
            })
            ->when($this->filterActive === 'active', function ($query): void {
                $query->where('active', true);
            })
            ->when($this->filterActive === 'inactive', function ($query): void {
                $query->where('active', false);
            })
            ->orderBy('position')
            ->orderBy('name')
            ->get();
    }

    #[Computed]
    public function activePlayersCount(): int
    {
        return Player::query()->active()->count();
    }

    #[Computed]
    public function totalPlayersCount(): int
    {
        return Player::query()->count();
    }

    #[Computed]
    public function averageTrainingBar(): int
    {
        return (int) round((float) Player::query()->avg('training_bar'));
    }

    /**
     * @return array<string, string>
     */
    #[Computed]
    public function positionOptions(): array
    {
        return PlayerPosition::options();
    }

    public function mount(): void
    {
        $this->position = PlayerPosition::Setter->value;
    }

    public function savePlayer(): void
    {
        $validated = $this->validate($this->rules(), $this->messages());

        $player = $this->editingPlayerId === null
            ? new Player()
            : Player::query()->findOrFail($this->editingPlayerId);

        $player->fill([
            'name' => $validated['name'],
            'position' => $validated['position'],
            'training_bar' => (int) $validated['trainingBar'],
            'active' => $validated['active'],
        ]);

        $player->save();

        $this->resetForm();
        $this->dispatch('player-saved');
        unset($this->players, $this->activePlayersCount, $this->totalPlayersCount, $this->averageTrainingBar);
    }

    public function editPlayer(int $playerId): void
    {
        $player = Player::query()->findOrFail($playerId);

        $this->editingPlayerId = $player->id;
        $this->name = $player->name;
        $this->position = $player->position->value;
        $this->trainingBar = $player->training_bar;
        $this->active = $player->active;

        $this->resetValidation();
    }

    public function cancelEditing(): void
    {
        $this->resetForm();
    }

    public function updatedFilterPosition(): void
    {
        unset($this->players);
    }

    public function updatedFilterActive(): void
    {
        unset($this->players);
    }

    public function updatedSearch(): void
    {
        unset($this->players);
    }

    /**
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:3', 'max:255', Rule::unique(Player::class, 'name')->ignore($this->editingPlayerId)],
            'position' => ['required', Rule::enum(PlayerPosition::class)],
            'trainingBar' => ['required', 'integer', 'between:0,100'],
            'active' => ['required', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function messages(): array
    {
        return [
            'name.required' => 'Podaj nazwe zawodnika.',
            'name.min' => 'Nazwa zawodnika musi miec co najmniej 3 znaki.',
            'name.unique' => 'Zawodnik o tej nazwie juz istnieje.',
            'position.required' => 'Wybierz pozycje.',
            'position.enum' => 'Wybrana pozycja jest nieprawidlowa.',
            'trainingBar.required' => 'Podaj aktualny pasek treningowy.',
            'trainingBar.integer' => 'Pasek treningowy musi byc liczba calkowita.',
            'trainingBar.between' => 'Pasek treningowy musi miescic sie w zakresie 0-100.',
            'active.required' => 'Okresl status aktywnosci zawodnika.',
        ];
    }

    protected function resetForm(): void
    {
        $this->reset(['editingPlayerId', 'name', 'trainingBar']);
        $this->position = PlayerPosition::Setter->value;
        $this->active = true;
        $this->resetValidation();
    }
};
?>

<div class="flex h-full w-full flex-1 flex-col gap-6 p-4 md:p-6">
    <section class="flex flex-col gap-4 rounded-3xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-700 dark:bg-zinc-900 lg:flex-row lg:items-end lg:justify-between">
        <div class="space-y-2">
            <flux:heading size="xl" level="1">Zawodnicy</flux:heading>
            <flux:text class="max-w-2xl text-zinc-600 dark:text-zinc-300">
                Tu zarzadzasz pula zawodnikow dla optymalizacji. Mozesz filtrowac liste, dodawac nowe rekordy i korygowac pasek treningowy przed analiza meczu.
            </flux:text>
        </div>

        <div class="flex items-center gap-3">
            <flux:badge color="emerald">{{ $this->activePlayersCount }} aktywnych</flux:badge>
            <flux:button variant="ghost" :href="route('optimizer.create')" wire:navigate>
                Przejdz do optymalizacji
            </flux:button>
        </div>
    </section>

    <section class="grid gap-4 md:grid-cols-3">
        <div class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
            <flux:text class="text-sm uppercase tracking-[0.2em] text-zinc-500 dark:text-zinc-400">Wszyscy zawodnicy</flux:text>
            <div class="mt-3 text-4xl font-semibold text-zinc-950 dark:text-zinc-50">{{ $this->totalPlayersCount }}</div>
        </div>
        <div class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
            <flux:text class="text-sm uppercase tracking-[0.2em] text-zinc-500 dark:text-zinc-400">Aktywni</flux:text>
            <div class="mt-3 text-4xl font-semibold text-zinc-950 dark:text-zinc-50">{{ $this->activePlayersCount }}</div>
        </div>
        <div class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
            <flux:text class="text-sm uppercase tracking-[0.2em] text-zinc-500 dark:text-zinc-400">Sredni pasek</flux:text>
            <div class="mt-3 text-4xl font-semibold text-zinc-950 dark:text-zinc-50">{{ $this->averageTrainingBar }}%</div>
        </div>
    </section>

    <section class="grid gap-6 xl:grid-cols-[0.72fr_1.28fr]">
        <div class="rounded-3xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
            <div class="flex items-center justify-between gap-4">
                <flux:heading size="lg">{{ $editingPlayerId === null ? 'Dodaj zawodnika' : 'Edytuj zawodnika' }}</flux:heading>

                @if ($editingPlayerId !== null)
                    <flux:button variant="ghost" wire:click="cancelEditing">
                        Anuluj
                    </flux:button>
                @endif
            </div>

            <form wire:submit="savePlayer" class="mt-6 space-y-5">
                <flux:input wire:model.live.blur="name" label="Imie i nazwisko" placeholder="np. Jan Nowak" />
                @error('name')
                    <flux:text class="-mt-3 text-sm text-rose-600 dark:text-rose-400">{{ $message }}</flux:text>
                @enderror

                <flux:select wire:model="position" label="Pozycja">
                    @foreach ($this->positionOptions as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </flux:select>
                @error('position')
                    <flux:text class="-mt-3 text-sm text-rose-600 dark:text-rose-400">{{ $message }}</flux:text>
                @enderror

                <flux:input wire:model.live.blur="trainingBar" label="Pasek treningowy" type="number" min="0" max="100" badge="%" />
                @error('trainingBar')
                    <flux:text class="-mt-3 text-sm text-rose-600 dark:text-rose-400">{{ $message }}</flux:text>
                @enderror

                <flux:switch wire:model="active" label="Aktywny w analizie" description="Wylaczenie ukrywa zawodnika z przyszlych analiz." />

                <div class="flex items-center gap-4">
                    <flux:button variant="primary" type="submit">
                        {{ $editingPlayerId === null ? 'Dodaj zawodnika' : 'Zapisz zmiany' }}
                    </flux:button>

                    <x-action-message on="player-saved">
                        Zapisano.
                    </x-action-message>
                </div>
            </form>
        </div>

        <div class="overflow-hidden rounded-3xl border border-zinc-200 bg-white shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
            <div class="border-b border-zinc-200 px-6 py-5 dark:border-zinc-700">
                <div class="flex flex-col gap-5">
                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <flux:heading size="lg">Aktualna pula zawodnikow</flux:heading>
                            <flux:text class="mt-1 text-sm text-zinc-600 dark:text-zinc-300">Filtry dzialaja bez przeladowania strony i od razu zawezaja liste.</flux:text>
                        </div>
                        <flux:badge color="sky">{{ $this->players->count() }} wynikow</flux:badge>
                    </div>

                    <div class="grid gap-4 md:grid-cols-[1.2fr_0.8fr_0.8fr]">
                        <flux:input wire:model.live.debounce.300ms="search" label="Szukaj" placeholder="Szukaj po nazwie" />
                        <flux:select wire:model.live="filterPosition" label="Pozycja" placeholder="Wszystkie pozycje">
                            <option value="">Wszystkie pozycje</option>
                            @foreach ($this->positionOptions as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </flux:select>
                        <flux:select wire:model.live="filterActive" label="Status">
                            <option value="all">Wszyscy</option>
                            <option value="active">Tylko aktywni</option>
                            <option value="inactive">Tylko nieaktywni</option>
                        </flux:select>
                    </div>
                </div>
            </div>

            @if ($this->players->isEmpty())
                <div class="px-6 py-12">
                    <flux:callout icon="magnifying-glass" color="amber">
                        <flux:callout.heading>Brak wynikow</flux:callout.heading>
                        <flux:callout.text>Zmodyfikuj filtry albo dodaj nowego zawodnika po lewej stronie.</flux:callout.text>
                    </flux:callout>
                </div>
            @else
                <div class="divide-y divide-zinc-200 dark:divide-zinc-700">
                    @foreach ($this->players as $player)
                        <div wire:key="{{ $player->id }}" class="grid gap-4 px-6 py-4 md:grid-cols-[1.1fr_0.8fr_130px_120px_110px] md:items-center">
                            <div class="min-w-0">
                                <flux:text class="truncate font-medium text-zinc-950 dark:text-zinc-50">{{ $player->name }}</flux:text>
                            </div>
                            <div>
                                <flux:badge color="sky">{{ $player->position->label() }}</flux:badge>
                            </div>
                            <div>
                                <flux:text class="text-sm text-zinc-600 dark:text-zinc-300">{{ $player->training_bar }}%</flux:text>
                                <div class="mt-2 h-2 overflow-hidden rounded-full bg-zinc-100 dark:bg-zinc-800">
                                    <div class="h-full rounded-full bg-emerald-500" style="width: {{ $player->training_bar }}%"></div>
                                </div>
                            </div>
                            <div>
                                <flux:badge :color="$player->active ? 'emerald' : 'amber'">
                                    {{ $player->active ? 'aktywny' : 'nieaktywny' }}
                                </flux:badge>
                            </div>
                            <div class="flex justify-start md:justify-end">
                                <flux:button variant="ghost" wire:click="editPlayer({{ $player->id }})">
                                    Edytuj
                                </flux:button>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    </section>
</div>
