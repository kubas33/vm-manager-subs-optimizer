<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-white dark:bg-zinc-800">
        <flux:header container class="border-b border-zinc-200 bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900">
            <flux:sidebar.toggle class="lg:hidden mr-2" icon="bars-2" inset="left" />

            <x-app-logo href="{{ route('dashboard') }}" wire:navigate />

            <flux:navbar class="-mb-px max-lg:hidden">
                <flux:navbar.item icon="layout-grid" :href="route('dashboard')" :current="request()->routeIs('dashboard')" wire:navigate>
                    Dashboard
                </flux:navbar.item>
                <flux:navbar.item icon="users" :href="route('players.index')" :current="request()->routeIs('players.*')" wire:navigate>
                    Zawodnicy
                </flux:navbar.item>
                <flux:navbar.item icon="sparkles" :href="route('optimizer.create')" :current="request()->routeIs('optimizer.*')" wire:navigate>
                    Optymalizacja
                </flux:navbar.item>
            </flux:navbar>

            <flux:spacer />

            @auth
                <x-desktop-user-menu />
            @elseif (! config('auth.disable_auth'))
                <flux:button :href="route('login')" variant="ghost" wire:navigate>
                    Zaloguj się
                </flux:button>
            @endauth
        </flux:header>

        <!-- Mobile Menu -->
        <flux:sidebar collapsible="mobile" sticky class="lg:hidden border-e border-zinc-200 bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900">
            <flux:sidebar.header>
                <x-app-logo :sidebar="true" href="{{ route('dashboard') }}" wire:navigate />
                <flux:sidebar.collapse class="in-data-flux-sidebar-on-desktop:not-in-data-flux-sidebar-collapsed-desktop:-mr-2" />
            </flux:sidebar.header>

            <flux:sidebar.nav>
                <flux:sidebar.group heading="Aplikacja">
                    <flux:sidebar.item icon="layout-grid" :href="route('dashboard')" :current="request()->routeIs('dashboard')" wire:navigate>
                        Dashboard
                    </flux:sidebar.item>
                    <flux:sidebar.item icon="users" :href="route('players.index')" :current="request()->routeIs('players.*')" wire:navigate>
                        Zawodnicy
                    </flux:sidebar.item>
                    <flux:sidebar.item icon="sparkles" :href="route('optimizer.create')" :current="request()->routeIs('optimizer.*')" wire:navigate>
                        Optymalizacja
                    </flux:sidebar.item>
                </flux:sidebar.group>
            </flux:sidebar.nav>

            <flux:spacer />

            @auth
                <flux:sidebar.nav>
                    <flux:sidebar.item icon="cog-6-tooth" :href="route('profile.edit')" :current="request()->routeIs('profile.edit', 'security.edit', 'appearance.edit')" wire:navigate>
                        Ustawienia
                    </flux:sidebar.item>
                </flux:sidebar.nav>
            @endauth
        </flux:sidebar>

        {{ $slot }}

        @fluxScripts
    </body>
</html>
