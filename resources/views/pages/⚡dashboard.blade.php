<?php

use App\Enums\PlayerPosition;
use App\Models\Player;
use App\Packages\VmManagerApi\Services\VmManagerApiService;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Dashboard')] class extends Component
{
    public string $vmLogin = '';

    public string $vmPassword = '';

    public string $vmLoginStatus = '';

    #[Computed]
    public function vmConnected(): bool
    {
        return app(VmManagerApiService::class)->isAuthenticated();
    }

    public function loginToVm(): void
    {
        $this->authorizeVmAction();
        $this->resetValidation('vmConnection');
        $this->vmLoginStatus = '';
        $rateLimitKey = 'vm-login:'.hash('sha256', session()->getId().'|'.request()->ip());

        try {
            $this->validate([
                'vmLogin' => ['required', 'string', 'max:255'],
                'vmPassword' => ['required', 'string', 'max:1024'],
            ], [
                'vmLogin.required' => 'Podaj login VM Manager.',
                'vmPassword.required' => 'Podaj hasło VM Manager.',
            ]);

            if (RateLimiter::tooManyAttempts($rateLimitKey, 5)) {
                $this->addError('vmConnection', 'Zbyt wiele prób logowania. Spróbuj ponownie za minutę.');

                return;
            }

            RateLimiter::hit($rateLimitKey, 60);
            app(VmManagerApiService::class)->login($this->vmLogin, $this->vmPassword);
            RateLimiter::clear($rateLimitKey);
            $this->vmLoginStatus = 'Połączono z VM Manager. Możesz importować paski i wysyłać zmiany.';
        } catch (\Illuminate\Validation\ValidationException $exception) {
            throw $exception;
        } catch (\Throwable) {
            $this->addError('vmConnection', 'Nie udało się zalogować do VM Manager. Sprawdź login, hasło i adres API.');
        } finally {
            $this->vmPassword = '';
            unset($this->vmConnected);
        }
    }

    public function logoutFromVm(): void
    {
        $this->authorizeVmAction();
        app(VmManagerApiService::class)->logout();
        $this->vmPassword = '';
        $this->vmLoginStatus = 'Rozłączono z VM Manager.';
        unset($this->vmConnected);
    }

    private function authorizeVmAction(): void
    {
        abort_unless(config('auth.disable_auth') || auth()->check(), 403);
    }

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

    <section class="rounded-3xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
        <flux:heading size="lg">Połączenie z VM Manager</flux:heading>
        <flux:text class="mt-1 max-w-2xl text-sm text-zinc-600 dark:text-zinc-300">
            Zaloguj się raz, żeby dostać token sesji używany przy imporcie pasków i wysyłaniu zmian do gry.
        </flux:text>

        @if ($this->vmConnected)
            <div class="mt-4 flex flex-wrap items-center gap-3">
                <flux:badge color="emerald">Połączono</flux:badge>
                <flux:button wire:click="logoutFromVm">Rozłącz</flux:button>
                <flux:button variant="ghost" :href="route('players.index')" wire:navigate>
                    Importuj paski
                </flux:button>
            </div>
        @else
            <form wire:submit="loginToVm" class="mt-4 flex flex-col gap-4 sm:flex-row sm:items-end">
                <flux:input wire:model="vmLogin" label="Login VM Manager" autocomplete="username" />
                <flux:input wire:model="vmPassword" type="password" label="Hasło VM Manager" autocomplete="current-password" />
                <flux:button type="submit" variant="primary">Połącz z VM Manager</flux:button>
            </form>
        @endif

        @if ($vmLoginStatus !== '')
            <flux:text class="mt-3" role="status">{{ $vmLoginStatus }}</flux:text>
        @endif
        <flux:error name="vmConnection" />
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
