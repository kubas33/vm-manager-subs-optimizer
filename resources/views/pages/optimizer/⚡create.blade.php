<?php

use App\Enums\PlayerPosition;
use App\MatchScenario;
use App\Models\Player;
use App\ScenarioSet;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Optymalizacja')] class extends Component
{
    public string $primaryPosition = '';
    public string $secondaryPosition = '';
    public string $scenarioMode = 'preset';
    public string $presetKey = 'standard_3_0';
    public string $singleScenario = '25:20, 25:18, 25:22';
    public string $multipleScenarios = "25:20, 25:18, 25:22\n25:22, 22:25, 25:21, 25:19";

    public function mount(): void
    {
        $this->primaryPosition = PlayerPosition::Setter->value;
        $this->secondaryPosition = PlayerPosition::MiddleBlocker->value;
    }

    #[Computed]
    public function positionOptions(): array
    {
        return PlayerPosition::options();
    }

    #[Computed]
    public function activePlayersCount(): int
    {
        return Player::query()->active()->count();
    }

    /**
     * @return array<string, array{label: string, description: string}>
     */
    #[Computed]
    public function scenarioModes(): array
    {
        return [
            'preset' => [
                'label' => 'Preset',
                'description' => 'Szybki wybór gotowego przebiegu meczu.',
            ],
            'single' => [
                'label' => 'Jeden scenariusz ręczny',
                'description' => 'Wpisz jeden przebieg meczu do analizy.',
            ],
            'multiple' => [
                'label' => 'Kilka scenariuszy ręcznych',
                'description' => 'Wklej kilka wariantów, po jednym w wierszu.',
            ],
        ];
    }

    /**
     * @return array<string, array{label: string, description: string, scenario: string}>
     */
    #[Computed]
    public function presetOptions(): array
    {
        return [
            'easy_3_0' => [
                'label' => 'Łatwe 3:0',
                'description' => 'Jednostronny mecz z krótkimi setami.',
                'scenario' => '25:12, 25:14, 25:13',
            ],
            'standard_3_0' => [
                'label' => 'Standardowe 3:0',
                'description' => 'Najprostszy wariant bazowy dla MVP.',
                'scenario' => '25:20, 25:18, 25:22',
            ],
            'standard_3_1' => [
                'label' => 'Standardowe 3:1',
                'description' => 'Mecz z jednym przegranym setem.',
                'scenario' => '25:21, 22:25, 25:20, 25:19',
            ],
            'hard_3_2' => [
                'label' => 'Trudne 3:2',
                'description' => 'Pełny, długi mecz z tie-breakiem.',
                'scenario' => '25:23, 22:25, 25:21, 20:25, 15:12',
            ],
        ];
    }

    /**
     * @return array<int, array{value: string, label: string, active_players: int}>
     */
    #[Computed]
    public function selectedPositionSummaries(): array
    {
        $positions = collect([$this->primaryPosition, $this->secondaryPosition])
            ->filter()
            ->values();

        if ($positions->isEmpty()) {
            return [];
        }

        $counts = Player::query()
            ->active()
            ->whereIn('position', $positions->all())
            ->selectRaw('position, count(*) as aggregate')
            ->groupBy('position')
            ->pluck('aggregate', 'position');

        return $positions
            ->map(function (string $value) use ($counts): ?array {
                $position = PlayerPosition::tryFrom($value);

                if ($position === null) {
                    return null;
                }

                return [
                    'value' => $value,
                    'label' => $position->label(),
                    'active_players' => (int) ($counts[$value] ?? 0),
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @return array<int, array{label: string, input: string, sets: array<int, array{our_score: int, opponent_score: int, actions: int}>, sets_count: int, total_actions: int}>
     */
    #[Computed]
    public function scenarioPreview(): array
    {
        try {
            return $this->buildScenarioSet([
                'scenarioMode' => $this->scenarioMode,
                'presetKey' => $this->presetKey,
                'singleScenario' => $this->singleScenario,
                'multipleScenarios' => $this->multipleScenarios,
            ])->toArray();
        } catch (ValidationException) {
            return [];
        }
    }

    public function updatedScenarioMode(): void
    {
        $this->resetValidation();
    }

    public function submit(): void
    {
        $validated = $this->validate($this->rules(), $this->messages());
        $payload = $this->normalizedPayload($validated);

        session()->put('optimizer.input', $payload);

        $this->redirectRoute('optimizer.result', navigate: true);
    }

    /**
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        return [
            'primaryPosition' => ['required', Rule::enum(PlayerPosition::class)],
            'secondaryPosition' => ['required', Rule::enum(PlayerPosition::class)],
            'scenarioMode' => ['required', Rule::in(array_keys($this->scenarioModes()))],
            'presetKey' => [Rule::requiredIf($this->scenarioMode === 'preset'), Rule::in(array_keys($this->presetOptions()))],
            'singleScenario' => [Rule::requiredIf($this->scenarioMode === 'single'), 'string'],
            'multipleScenarios' => [Rule::requiredIf($this->scenarioMode === 'multiple'), 'string'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function messages(): array
    {
        return [
            'primaryPosition.required' => 'Wybierz pierwszą pozycję do analizy.',
            'primaryPosition.enum' => 'Pierwsza pozycja jest nieprawidłowa.',
            'secondaryPosition.required' => 'Wybierz drugą pozycję do analizy.',
            'secondaryPosition.enum' => 'Druga pozycja jest nieprawidłowa.',
            'scenarioMode.required' => 'Wybierz tryb scenariusza.',
            'scenarioMode.in' => 'Wybrany tryb scenariusza jest nieprawidłowy.',
            'presetKey.required' => 'Wybierz preset scenariusza.',
            'presetKey.in' => 'Wybrany preset nie istnieje.',
            'singleScenario.required' => 'Wpisz scenariusz meczu.',
            'multipleScenarios.required' => 'Wpisz co najmniej jeden scenariusz.',
        ];
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array{
     *     positions: array<int, array{value: string, label: string, active_players: int}>,
     *     scenario_mode: string,
     *     scenario_mode_label: string,
     *     scenario_source: string,
     *     scenario_source_label: string,
     *     scenarios: array<int, array{label: string, input: string, sets: array<int, array{our_score: int, opponent_score: int, actions: int}>, sets_count: int, total_actions: int}>
     * }
     */
    protected function normalizedPayload(array $validated): array
    {
        $scenarioSource = $validated['scenarioMode'] === 'preset'
            ? (string) $validated['presetKey']
            : $validated['scenarioMode'];

        $scenarioSourceLabel = $validated['scenarioMode'] === 'preset'
            ? $this->presetOptions()[$validated['presetKey']]['label']
            : $this->scenarioModeLabel($validated['scenarioMode']);

        return [
            'positions' => $this->normalizePositions($validated),
            'scenario_mode' => $validated['scenarioMode'],
            'scenario_mode_label' => $this->scenarioModeLabel($validated['scenarioMode']),
            'scenario_source' => $scenarioSource,
            'scenario_source_label' => $scenarioSourceLabel,
            'scenarios' => $this->buildScenarioSet($validated)->toArray(),
        ];
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<int, array{value: string, label: string, active_players: int}>
     */
    protected function normalizePositions(array $validated): array
    {
        $counts = Player::query()
            ->active()
            ->whereIn('position', [$validated['primaryPosition'], $validated['secondaryPosition']])
            ->selectRaw('position, count(*) as aggregate')
            ->groupBy('position')
            ->pluck('aggregate', 'position');

        return collect([$validated['primaryPosition'], $validated['secondaryPosition']])
            ->map(fn (string $value) => [
                'value' => $value,
                'label' => PlayerPosition::from($value)->label(),
                'active_players' => (int) ($counts[$value] ?? 0),
            ])
            ->all();
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<int, array{label: string, input: string, sets: array<int, array{our_score: int, opponent_score: int, actions: int}>, sets_count: int, total_actions: int}>
     */
    protected function buildScenarioSet(array $validated): ScenarioSet
    {
        try {
            return match ($validated['scenarioMode']) {
                'preset' => ScenarioSet::single($this->presetScenario((string) $validated['presetKey'])),
                'single' => ScenarioSet::single(MatchScenario::fromInput((string) $validated['singleScenario'], 'Scenariusz ręczny')),
                'multiple' => $this->multipleScenarioSet((string) $validated['multipleScenarios']),
                default => $this->throwScenarioValidation('scenarioMode', 'Wybrany tryb scenariusza jest nieprawidłowy.'),
            };
        } catch (\InvalidArgumentException $exception) {
            $this->throwScenarioValidation(
                $validated['scenarioMode'] === 'preset'
                    ? 'presetKey'
                    : $this->scenarioInputField($validated['scenarioMode']),
                $exception->getMessage(),
            );
        }
    }

    /**
     * @return MatchScenario
     */
    protected function presetScenario(string $presetKey): MatchScenario
    {
        $preset = $this->presetOptions()[$presetKey] ?? null;

        if ($preset === null) {
            $this->throwScenarioValidation('presetKey', 'Wybierz poprawny preset scenariusza.');
        }

        return MatchScenario::fromInput($preset['scenario'], $preset['label']);
    }

    protected function multipleScenarioSet(string $scenarios): ScenarioSet
    {
        $lines = preg_split('/\r\n|\r|\n/', trim($scenarios));

        if ($lines === false || $lines === []) {
            $this->throwScenarioValidation('multipleScenarios', 'Wpisz co najmniej jeden scenariusz.');
        }

        if (collect($lines)->contains(fn (string $line) => trim($line) === '')) {
            $this->throwScenarioValidation('multipleScenarios', 'Usuń puste wiersze między scenariuszami.');
        }

        return ScenarioSet::fromInputs(array_values($lines));
    }

    protected function scenarioModeLabel(string $scenarioMode): string
    {
        return $this->scenarioModes()[$scenarioMode]['label'] ?? 'Nieznany tryb';
    }

    protected function scenarioInputField(string $scenarioMode): string
    {
        return match ($scenarioMode) {
            'multiple' => 'multipleScenarios',
            default => 'singleScenario',
        };
    }

    protected function throwScenarioValidation(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
};
?>

<div class="flex h-full w-full flex-1 flex-col gap-6 p-4 md:p-6">
    <section class="flex flex-col gap-4 rounded-3xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-700 dark:bg-zinc-900 lg:flex-row lg:items-end lg:justify-between">
        <div class="space-y-2">
            <flux:heading size="xl" level="1">Optymalizacja składu</flux:heading>
            <flux:text class="max-w-2xl text-zinc-600 dark:text-zinc-300">
                Wybierz dwie pozycje, tryb scenariusza i przebieg meczu. Ten etap zapisuje gotowe wejście pod przyszły silnik obliczeniowy i prowadzi do podsumowania wyniku.
            </flux:text>
        </div>

        <div class="flex items-center gap-3">
            <flux:badge color="emerald">{{ $this->activePlayersCount }} aktywnych</flux:badge>
            <flux:button variant="ghost" :href="route('players.index')" wire:navigate>
                Zarządzaj zawodnikami
            </flux:button>
        </div>
    </section>

    <section class="grid gap-6 xl:grid-cols-[1.15fr_0.85fr]">
        <div class="rounded-3xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
            <flux:heading size="lg">Dane wejściowe optymalizacji</flux:heading>

            <form wire:submit="submit" class="mt-6 space-y-8">
                <div class="space-y-4">
                    <div>
                        <flux:heading size="base">Dwie pozycje do analizy</flux:heading>
                        <flux:text class="mt-1 text-sm text-zinc-600 dark:text-zinc-300">
                            W MVP analizujemy dokładnie dwa sloty boiskowe. Oba mogą wskazywać tę samą pozycję, np. dwóch środkowych.
                        </flux:text>
                    </div>

                    <div class="grid gap-4 md:grid-cols-2">
                        <div>
                            <flux:select wire:model.live="primaryPosition" label="Pozycja 1">
                                @foreach ($this->positionOptions as $value => $label)
                                    <option value="{{ $value }}">{{ $label }}</option>
                                @endforeach
                            </flux:select>
                            @error('primaryPosition')
                                <flux:text class="mt-2 text-sm text-rose-600 dark:text-rose-400">{{ $message }}</flux:text>
                            @enderror
                        </div>

                        <div>
                            <flux:select wire:model.live="secondaryPosition" label="Pozycja 2">
                                @foreach ($this->positionOptions as $value => $label)
                                    <option value="{{ $value }}">{{ $label }}</option>
                                @endforeach
                            </flux:select>
                            @error('secondaryPosition')
                                <flux:text class="mt-2 text-sm text-rose-600 dark:text-rose-400">{{ $message }}</flux:text>
                            @enderror
                        </div>
                    </div>
                </div>

                <div class="space-y-4">
                    <div>
                        <flux:heading size="base">Tryb scenariusza</flux:heading>
                        <flux:text class="mt-1 text-sm text-zinc-600 dark:text-zinc-300">
                            Formularz dynamicznie pokazuje właściwe pole wejściowe dla wybranego trybu.
                        </flux:text>
                    </div>

                    <flux:radio.group wire:model.live="scenarioMode" variant="cards" class="max-lg:flex-col">
                        @foreach ($this->scenarioModes as $value => $mode)
                            <flux:radio wire:key="{{ $value }}" value="{{ $value }}">
                                <div class="space-y-1">
                                    <div class="font-medium text-zinc-950 dark:text-zinc-50">{{ $mode['label'] }}</div>
                                    <div class="text-sm text-zinc-600 dark:text-zinc-300">{{ $mode['description'] }}</div>
                                </div>
                            </flux:radio>
                        @endforeach
                    </flux:radio.group>
                    @error('scenarioMode')
                        <flux:text class="text-sm text-rose-600 dark:text-rose-400">{{ $message }}</flux:text>
                    @enderror
                </div>

                @if ($scenarioMode === 'preset')
                    <div class="space-y-4">
                        <flux:select wire:model.live="presetKey" label="Preset scenariusza">
                            @foreach ($this->presetOptions as $value => $preset)
                                <option value="{{ $value }}">{{ $preset['label'] }}</option>
                            @endforeach
                        </flux:select>
                        @error('presetKey')
                            <flux:text class="-mt-2 text-sm text-rose-600 dark:text-rose-400">{{ $message }}</flux:text>
                        @enderror

                        @php($selectedPreset = $this->presetOptions[$presetKey] ?? null)

                        @if ($selectedPreset !== null)
                            <div class="rounded-2xl border border-zinc-200 bg-zinc-50/70 p-4 dark:border-zinc-700 dark:bg-zinc-800/60">
                                <flux:text class="font-medium text-zinc-950 dark:text-zinc-50">{{ $selectedPreset['label'] }}</flux:text>
                                <flux:text class="mt-2 text-sm text-zinc-600 dark:text-zinc-300">{{ $selectedPreset['description'] }}</flux:text>
                                <flux:text class="mt-3 text-sm text-zinc-800 dark:text-zinc-200">{{ $selectedPreset['scenario'] }}</flux:text>
                            </div>
                        @endif
                    </div>
                @elseif ($scenarioMode === 'single')
                    <div>
                        <flux:textarea
                            wire:model.live.blur="singleScenario"
                            label="Scenariusz meczu"
                            rows="4"
                            placeholder="np. 25:20, 25:18, 25:22"
                        />
                        <flux:text class="mt-2 text-sm text-zinc-600 dark:text-zinc-300">
                            Podaj od 3 do 5 setów, oddzielając je przecinkami.
                        </flux:text>
                        @error('singleScenario')
                            <flux:text class="mt-2 text-sm text-rose-600 dark:text-rose-400">{{ $message }}</flux:text>
                        @enderror
                    </div>
                @else
                    <div>
                        <flux:textarea
                            wire:model.live.blur="multipleScenarios"
                            label="Scenariusze meczu"
                            rows="6"
                            placeholder="25:20, 25:18, 25:22&#10;25:22, 22:25, 25:21, 25:19"
                        />
                        <flux:text class="mt-2 text-sm text-zinc-600 dark:text-zinc-300">
                            Wklej po jednym scenariuszu w każdym wierszu. Puste linie są traktowane jako błąd walidacji.
                        </flux:text>
                        @error('multipleScenarios')
                            <flux:text class="mt-2 text-sm text-rose-600 dark:text-rose-400">{{ $message }}</flux:text>
                        @enderror
                    </div>
                @endif

                <div class="flex flex-wrap items-center gap-3">
                    <flux:button variant="primary" type="submit">
                        Przejdź do wyniku
                    </flux:button>
                </div>
            </form>
        </div>

        <div class="rounded-3xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
            <flux:heading size="lg">Podgląd wejścia</flux:heading>

            <div class="mt-5 space-y-4">
                <div class="rounded-2xl border border-zinc-200 bg-zinc-50/70 p-4 dark:border-zinc-700 dark:bg-zinc-800/60">
                    <flux:text class="text-sm uppercase tracking-[0.2em] text-zinc-500 dark:text-zinc-400">Wybrane pozycje</flux:text>
                    <div class="mt-4 space-y-3">
                        @foreach ($this->selectedPositionSummaries as $summary)
                            <div wire:key="{{ $summary['value'].'-'.$loop->index }}" class="flex items-center justify-between gap-3 rounded-2xl border border-zinc-200 bg-white px-4 py-3 dark:border-zinc-700 dark:bg-zinc-900">
                                <div>
                                    <flux:text class="font-medium text-zinc-950 dark:text-zinc-50">{{ $summary['label'] }}</flux:text>
                                    <flux:text class="mt-1 text-sm text-zinc-600 dark:text-zinc-300">
                                        {{ $summary['active_players'] }} aktywnych zawodników dla tej pozycji
                                    </flux:text>
                                </div>
                                <flux:badge :color="$summary['active_players'] >= 2 ? 'emerald' : 'amber'">
                                    {{ $summary['active_players'] >= 2 ? 'gotowe' : 'uzupełnij' }}
                                </flux:badge>
                            </div>
                        @endforeach
                    </div>
                </div>

                <div class="rounded-2xl border border-zinc-200 bg-zinc-50/70 p-4 dark:border-zinc-700 dark:bg-zinc-800/60">
                    <div class="flex items-center justify-between gap-3">
                        <flux:text class="text-sm uppercase tracking-[0.2em] text-zinc-500 dark:text-zinc-400">Scenariusze</flux:text>
                        <flux:badge color="sky">{{ count($this->scenarioPreview) }}</flux:badge>
                    </div>

                    @if ($this->scenarioPreview === [])
                        <flux:text class="mt-4 text-sm text-zinc-600 dark:text-zinc-300">
                            Uzupełnij poprawne dane, a tutaj pokaże się znormalizowany podgląd setów gotowy do dalszych obliczeń.
                        </flux:text>
                    @else
                        <div class="mt-4 space-y-3">
                            @foreach ($this->scenarioPreview as $scenario)
                                <div wire:key="{{ $scenario['label'] }}" class="rounded-2xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
                                    <div class="flex items-start justify-between gap-4">
                                        <div>
                                            <flux:text class="font-medium text-zinc-950 dark:text-zinc-50">{{ $scenario['label'] }}</flux:text>
                                            <flux:text class="mt-1 text-sm text-zinc-600 dark:text-zinc-300">{{ $scenario['input'] }}</flux:text>
                                        </div>
                                        <flux:badge color="sky">{{ $scenario['sets_count'] }} sety</flux:badge>
                                    </div>
                                    <flux:text class="mt-3 text-sm text-zinc-600 dark:text-zinc-300">
                                        Łączna liczba akcji w scenariuszu: {{ $scenario['total_actions'] }}
                                    </flux:text>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>

                <flux:callout icon="clipboard-document-check" color="emerald">
                    <flux:callout.heading>Pierwszy przepływ MVP</flux:callout.heading>
                    <flux:callout.text>
                        Po submitcie formularza dane wejściowe trafiają do sesji i są pokazywane na ekranie wyniku jako podsumowanie pod przyszły ranking wariantów.
                    </flux:callout.text>
                </flux:callout>
            </div>
        </div>
    </section>
</div>
