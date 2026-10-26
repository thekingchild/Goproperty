<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-white dark:bg-zinc-800">
        <flux:sidebar sticky collapsible="mobile" class="border-e border-zinc-200 bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900">
            <flux:sidebar.header>
                <x-app-logo :sidebar="true" href="{{ route('dashboard') }}" wire:navigate />
                <flux:sidebar.collapse class="lg:hidden" />
            </flux:sidebar.header>

            <x-org-switcher class="px-1" />

            @php
                $inWorkspace = app(\App\Tenancy\OrganizationContext::class)->has();
            @endphp
            <flux:sidebar.nav>
                @if ($inWorkspace)
                    <flux:sidebar.item icon="home" :href="route('workspace.home', app(\App\Tenancy\OrganizationContext::class)->get())" :current="request()->routeIs('workspace.home')" wire:navigate>
                        {{ __('Home') }}
                    </flux:sidebar.item>
                @else
                    <flux:sidebar.item icon="home" :href="route('dashboard')" :current="request()->routeIs('dashboard')" wire:navigate>
                        {{ __('Dashboard') }}
                    </flux:sidebar.item>
                @endif
            </flux:sidebar.nav>

            <flux:spacer />

            {{-- Surfaces (ONBOARDING_COMPLIANCE.md § 1). --}}
            <flux:sidebar.nav>
                @foreach (auth()->user()->organizations()->orderBy('name')->get() as $organization)
                    <flux:sidebar.item icon="building-office-2" :href="route('workspace.home', $organization)" :current="request()->is('o/'.$organization->slug.'*')" wire:navigate>
                        {{ $organization->name }}
                    </flux:sidebar.item>
                @endforeach

                <flux:sidebar.item icon="home-modern" :href="route('portal.home')" :current="request()->routeIs('portal.*')" wire:navigate>
                    {{ __('My rentals') }}
                </flux:sidebar.item>

                <flux:sidebar.item icon="key" :href="route('owner.home')" :current="request()->routeIs('owner.*')" wire:navigate>
                    {{ __('My properties') }}
                </flux:sidebar.item>
            </flux:sidebar.nav>

            <x-desktop-user-menu class="hidden lg:block" :name="auth()->user()->name" />
        </flux:sidebar>

        <!-- Mobile User Menu -->
        <flux:header class="lg:hidden">
            <flux:sidebar.toggle class="lg:hidden" icon="bars-2" inset="left" />

            <flux:spacer />

            <flux:dropdown position="top" align="end">
                <flux:profile
                    :initials="auth()->user()->initials()"
                    icon-trailing="chevron-down"
                />

                <flux:menu>
                    <flux:menu.radio.group>
                        <div class="p-0 text-sm font-normal">
                            <div class="flex items-center gap-2 px-1 py-1.5 text-start text-sm">
                                <flux:avatar
                                    :name="auth()->user()->name"
                                    :initials="auth()->user()->initials()"
                                />

                                <div class="grid flex-1 text-start text-sm leading-tight">
                                    <flux:heading class="truncate">{{ auth()->user()->name }}</flux:heading>
                                    <flux:text class="truncate">{{ auth()->user()->email }}</flux:text>
                                </div>
                            </div>
                        </div>
                    </flux:menu.radio.group>

                    <flux:menu.separator />

                    <flux:menu.radio.group>
                        <flux:menu.item :href="route('profile.edit')" icon="cog" wire:navigate>
                            {{ __('Settings') }}
                        </flux:menu.item>
                    </flux:menu.radio.group>

                    <flux:menu.separator />

                    <form method="POST" action="{{ route('logout') }}" class="w-full">
                        @csrf
                        <flux:menu.item
                            as="button"
                            type="submit"
                            icon="arrow-right-start-on-rectangle"
                            class="w-full cursor-pointer"
                            data-test="logout-button"
                        >
                            {{ __('Log out') }}
                        </flux:menu.item>
                    </form>
                </flux:menu>
            </flux:dropdown>
        </flux:header>

        {{ $slot }}

        {{-- Bottom navigation on phones (UX_DESIGN.md § 2). --}}
        <nav class="fixed inset-x-0 bottom-0 z-30 grid grid-cols-4 border-t border-zinc-200 bg-white pb-[env(safe-area-inset-bottom)] lg:hidden dark:border-zinc-700 dark:bg-zinc-900" aria-label="{{ __('Main') }}" data-test="bottom-nav">
            @php
                $homeUrl = $inWorkspace ? route('workspace.home', app(\App\Tenancy\OrganizationContext::class)->get()) : route('dashboard');
                $tabs = [
                    ['label' => __('Home'), 'icon' => 'home', 'url' => $homeUrl, 'active' => request()->routeIs('dashboard', 'workspace.*')],
                    ['label' => __('Rentals'), 'icon' => 'home-modern', 'url' => route('portal.home'), 'active' => request()->routeIs('portal.*')],
                    ['label' => __('Properties'), 'icon' => 'key', 'url' => route('owner.home'), 'active' => request()->routeIs('owner.*')],
                    ['label' => __('Account'), 'icon' => 'user-circle', 'url' => route('profile.edit'), 'active' => request()->is('settings/*')],
                ];
            @endphp
            @foreach ($tabs as $tab)
                <a href="{{ $tab['url'] }}" wire:navigate @class(['flex min-h-14 flex-col items-center justify-center gap-0.5 text-xs font-medium', 'text-primary-700 dark:text-primary-300' => $tab['active'], 'text-zinc-500 dark:text-zinc-400' => ! $tab['active']]) @if ($tab['active']) aria-current="page" @endif>
                    <flux:icon :name="$tab['icon']" class="size-5" />
                    {{ $tab['label'] }}
                </a>
            @endforeach
        </nav>

        @persist('toast')
            <flux:toast.group>
                <flux:toast />
            </flux:toast.group>
        @endpersist

        @fluxScripts
    </body>
</html>
