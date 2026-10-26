{{--
    Organization switcher (ONBOARDING_COMPLIANCE.md § 1 "After login"): the
    organizations whose workspace the user may open (never one where they are
    only a tenant or property owner), their role in each, and the two cross-agency
    portals. Choosing an organization opens it and remembers it as the one
    to land on after signing in (organizations.switch).
--}}
@php
    $context = app(\App\Tenancy\OrganizationContext::class);
    $current = $context->has() ? $context->get() : null;
    $user = auth()->user();
    $organizations = $user?->workspaceOrganizations()->orderBy('name')->get() ?? collect();
    $roles = $user?->roleNamesByOrganization() ?? [];
    $roleLabel = fn (int $organizationId): ?string => collect($roles[$organizationId] ?? [])
        ->map(fn (string $name): string => \App\Enums\OrganizationRole::tryFrom($name)?->label() ?? $name)
        ->implode(', ') ?: null;
@endphp

<flux:dropdown position="bottom" align="start" {{ $attributes }}>
    <flux:button variant="ghost" icon:trailing="chevrons-up-down" class="w-full justify-between" data-test="org-switcher">
        <span class="flex min-w-0 flex-col items-start text-start">
            <span class="truncate">{{ $current?->name ?? __('Choose a workspace') }}</span>
            @if ($current && $roleLabel($current->id))
                <span class="truncate text-xs font-normal text-zinc-500 dark:text-zinc-400" data-test="current-role">{{ $roleLabel($current->id) }}</span>
            @endif
        </span>
    </flux:button>

    <flux:menu>
        <flux:menu.heading>{{ __('Workspaces') }}</flux:menu.heading>
        @forelse ($organizations as $organization)
            <form method="POST" action="{{ route('organizations.switch', $organization->slug) }}" class="w-full">
                @csrf
                <flux:menu.item as="button" type="submit" icon="building-office-2" class="w-full cursor-pointer" data-test="switch-organization">
                    <span class="flex min-w-0 flex-col items-start text-start">
                        <span class="truncate" @if ($current?->is($organization)) aria-current="true" @endif>{{ $organization->name }}</span>
                        @if ($roleLabel($organization->id))
                            <span class="truncate text-xs text-zinc-500 dark:text-zinc-400">{{ $roleLabel($organization->id) }}</span>
                        @endif
                    </span>
                </flux:menu.item>
            </form>
        @empty
            <flux:menu.item disabled>{{ __('No workspaces yet') }}</flux:menu.item>
        @endforelse
        <flux:menu.separator />
        <flux:menu.item icon="home-modern" :href="route('portal.home')" wire:navigate>{{ __('My rentals') }}</flux:menu.item>
        <flux:menu.item icon="key" :href="route('owner.home')" wire:navigate>{{ __('My properties') }}</flux:menu.item>
    </flux:menu>
</flux:dropdown>
