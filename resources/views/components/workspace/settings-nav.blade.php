{{--
    Tabs across the workspace's Settings pages (/o/{slug}/settings/…). Each
    tab shows only to members who may open its page; the pages check again.
    $current: the route name of the page showing it (Livewire re-renders
    run on its own route, so the page says which tab is open).
--}}
@props(['current'])

@php
    $organization = app(\App\Tenancy\OrganizationContext::class)->get();
    $user = auth()->user();
    $tabs = array_filter([
        ['route' => 'workspace.settings.profile', 'label' => __('Profile'), 'can' => $user?->can('viewSettings', $organization)],
        ['route' => 'workspace.settings.collections', 'label' => __('Payment collection'), 'can' => $user?->can('viewPaymentSettings', $organization)],
        ['route' => 'workspace.settings.branding', 'label' => __('Branding'), 'can' => $user?->can('viewSettings', $organization)],
        ['route' => 'workspace.settings.members', 'label' => __('Members'), 'can' => $user?->can('viewAny', \App\Models\Membership::class)],
    ], fn (array $tab): bool => $tab['can'] === true && \Illuminate\Support\Facades\Route::has($tab['route']));
@endphp

<nav class="-mt-2 flex gap-1 overflow-x-auto border-b border-zinc-200 dark:border-zinc-700" aria-label="{{ __('Settings') }}" data-test="settings-nav">
    @foreach ($tabs as $tab)
        <a href="{{ route($tab['route'], $organization) }}" wire:navigate
           @class([
               'whitespace-nowrap border-b-2 px-3 py-2 text-sm font-medium',
               'border-primary-700 text-primary-700 dark:border-primary-300 dark:text-primary-300' => $current === $tab['route'],
               'border-transparent text-zinc-600 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-white' => $current !== $tab['route'],
           ])
           @if ($current === $tab['route']) aria-current="page" @endif>
            {{ $tab['label'] }}
        </a>
    @endforeach
</nav>
