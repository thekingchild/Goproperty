{{--
    Workspace switcher (ONBOARDING_COMPLIANCE.md § 1 "After login"). A
    placeholder until P01-01: it lists the user's organizations and the two
    cross-agency portals; remembering the last choice comes later.
--}}
@php
    $current = app(\App\Tenancy\OrganizationContext::class)->has() ? app(\App\Tenancy\OrganizationContext::class)->get() : null;
    $organizations = auth()->user()?->organizations()->orderBy('name')->get() ?? collect();
@endphp

<flux:dropdown position="bottom" align="start" {{ $attributes }}>
    <flux:button variant="ghost" icon:trailing="chevrons-up-down" class="w-full justify-between" data-test="org-switcher">
        <span class="truncate">{{ $current?->name ?? __('Choose a workspace') }}</span>
    </flux:button>

    <flux:menu>
        <flux:menu.heading>{{ __('Workspaces') }}</flux:menu.heading>
        @forelse ($organizations as $organization)
            <flux:menu.item icon="building-office-2" :href="route('workspace.home', $organization)" wire:navigate>
                {{ $organization->name }}
            </flux:menu.item>
        @empty
            <flux:menu.item disabled>{{ __('No workspaces yet') }}</flux:menu.item>
        @endforelse
        <flux:menu.separator />
        <flux:menu.item icon="home-modern" :href="route('portal.home')" wire:navigate>{{ __('My rentals') }}</flux:menu.item>
        <flux:menu.item icon="key" :href="route('owner.home')" wire:navigate>{{ __('My properties') }}</flux:menu.item>
    </flux:menu>
</flux:dropdown>
