{{--
    Organization picker (ONBOARDING_COMPLIANCE.md § 1 "After login", UX_DESIGN.md § 3.3):
    where a member of several organizations lands until they pick one, and
    the start page for users without a workspace.
--}}
@php
    $user = auth()->user();
    $organizations = $user->workspaceOrganizations()->orderBy('name')->get();
    $roles = $user->roleNamesByOrganization();
@endphp

<x-layouts::app :title="__('Dashboard')">
    <x-page :heading="__('Pick where to start')" :description="$organizations->isEmpty() ? __('Choose a workspace, or open your rentals or properties.') : __('Choose the workspace to open. We\'ll open it straight away next time you sign in.')">
        @if ($organizations->isEmpty())
            <x-empty-state icon="squares-2x2" :heading="__('Pick where to start')" :description="__('Your workspaces and portals are in the menu.')">
                <x-slot:action>
                    <flux:button variant="primary" :href="route('portal.home')" wire:navigate>{{ __('My rentals') }}</flux:button>
                </x-slot:action>
            </x-empty-state>
        @else
            <ul class="grid gap-3 sm:grid-cols-2" data-test="organization-picker">
                @foreach ($organizations as $organization)
                    <li>
                        <form method="POST" action="{{ route('organizations.switch', $organization->slug) }}">
                            @csrf
                            <button type="submit" class="flex min-h-14 w-full items-center gap-3 rounded-xl border border-zinc-200 bg-white p-4 text-start hover:border-primary-500 focus-visible:outline-2 focus-visible:outline-primary-600 dark:border-zinc-700 dark:bg-zinc-900">
                                <flux:icon name="building-office-2" class="size-6 shrink-0 text-zinc-500" />
                                <span class="flex min-w-0 flex-1 flex-col">
                                    <span class="truncate font-medium text-zinc-900 dark:text-white">{{ $organization->name }}</span>
                                    <span class="truncate text-sm text-zinc-500 dark:text-zinc-400">
                                        {{ collect($roles[$organization->id] ?? [])->map(fn (string $name): string => \App\Enums\OrganizationRole::tryFrom($name)?->label() ?? $name)->implode(', ') ?: __('Member') }}
                                    </span>
                                </span>
                                @if ($organization->pivot?->is_default)
                                    <flux:badge size="sm">{{ __('Last used') }}</flux:badge>
                                @endif
                            </button>
                        </form>
                    </li>
                @endforeach
            </ul>

            <div class="mt-6 flex flex-wrap gap-3">
                <flux:button :href="route('portal.home')" icon="home-modern" wire:navigate>{{ __('My rentals') }}</flux:button>
                <flux:button :href="route('owner.home')" icon="key" wire:navigate>{{ __('My properties') }}</flux:button>
            </div>
        @endif
    </x-page>
</x-layouts::app>
