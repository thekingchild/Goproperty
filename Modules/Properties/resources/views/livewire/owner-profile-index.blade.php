{{--
    Owners (/o/{organization}/owners; Modules\Properties\Livewire\OwnerProfileIndex).
    Rows stack into cards on phones (UX_DESIGN.md § 6). Buttons show only
    for what the member may do; every action checks its policy again.
--}}
<x-page :heading="__('Owners')" :description="__('The people and companies who own the buildings :organization manages.', ['organization' => $organization->name])">
    @if ($canManage)
        <x-slot:actions>
            <flux:button variant="primary" icon="plus" :href="route('workspace.owners.create', $organization)" wire:navigate data-test="add-owner">{{ __('Add owner') }}</flux:button>
        </x-slot:actions>
    @endif

    @if ($notice)
        <flux:callout variant="success" icon="check-circle" :heading="$notice" role="status" data-test="owners-notice" />
    @endif

    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div class="w-full sm:max-w-sm">
            <flux:input wire:model.live.debounce.400ms="search" icon="magnifying-glass" type="search" :placeholder="__('Search by name, email or phone')" :aria-label="__('Search owners')" maxlength="100" />
        </div>
        <div class="flex gap-1" role="group" aria-label="{{ __('Show') }}">
            <flux:button size="sm" :variant="$archived ? 'ghost' : 'filled'" wire:click="$set('archived', false)" :aria-pressed="$archived ? 'false' : 'true'">{{ __('Active') }}</flux:button>
            <flux:button size="sm" :variant="$archived ? 'filled' : 'ghost'" wire:click="$set('archived', true)" :aria-pressed="$archived ? 'true' : 'false'" data-test="show-archived">{{ __('Archived') }}</flux:button>
        </div>
    </div>

    @if ($owners->isEmpty())
        @if (trim($search) !== '')
            <x-empty-state icon="magnifying-glass" :heading="__('No owner matches “:search”', ['search' => $search])" :description="__('Check the spelling, or search by phone number or email instead.')" />
        @elseif ($archived)
            <x-empty-state icon="archive-box" :heading="__('No archived owners')" :description="__('Owners you archive are kept here, and you can restore them at any time.')" />
        @else
            <x-empty-state icon="user-group" :heading="__('No owners yet')" :description="__('Add the people and companies who own the buildings you manage. You choose an owner when you add a building.')">
                @if ($canManage)
                    <x-slot:action>
                        <flux:button variant="primary" icon="plus" :href="route('workspace.owners.create', $organization)" wire:navigate>{{ __('Add owner') }}</flux:button>
                    </x-slot:action>
                @endif
            </x-empty-state>
        @endif
    @else
        <ul class="divide-y divide-zinc-100 rounded-xl border border-zinc-200 bg-white dark:divide-zinc-800 dark:border-zinc-700 dark:bg-zinc-900" data-test="owners-list">
            @foreach ($owners as $owner)
                <li class="flex flex-col gap-3 p-4 sm:flex-row sm:items-center sm:justify-between" wire:key="owner-{{ $owner->id }}" data-owner="{{ $owner->id }}">
                    <div class="flex min-w-0 items-center gap-3">
                        <flux:avatar :name="$owner->display_name" size="sm" :icon="$owner->type === \Modules\Properties\Enums\OwnerType::Company ? 'building-office' : null" />
                        <div class="flex min-w-0 flex-col">
                            @if ($archived)
                                <span class="truncate font-medium text-zinc-900 dark:text-white">{{ $owner->display_name }}</span>
                            @else
                                <a href="{{ route('workspace.owners.edit', [$organization, $owner]) }}" wire:navigate class="truncate font-medium text-zinc-900 hover:underline dark:text-white">{{ $owner->display_name }}</a>
                            @endif
                            <span class="truncate text-sm text-zinc-500 dark:text-zinc-400">
                                {{ collect([$owner->phone, $owner->email, $owner->state])->filter()->join(' · ') ?: __('No contact details') }}
                            </span>
                        </div>
                    </div>

                    <div class="flex flex-wrap items-center gap-2">
                        <flux:badge size="sm" color="zinc">{{ __($owner->type->label()) }}</flux:badge>
                        @if ($canManage)
                            @if ($archived)
                                <flux:button size="sm" wire:click="restore({{ $owner->id }})" data-test="restore-{{ $owner->id }}">{{ __('Restore') }}</flux:button>
                            @else
                                <flux:button size="sm" :href="route('workspace.owners.edit', [$organization, $owner])" wire:navigate>{{ __('Edit') }}</flux:button>
                                <flux:button size="sm" variant="danger" wire:click="archive({{ $owner->id }})" wire:confirm="{{ __('Archive :name? The record is kept and you can restore it later.', ['name' => $owner->display_name]) }}" data-test="archive-{{ $owner->id }}">{{ __('Archive') }}</flux:button>
                            @endif
                        @endif
                    </div>
                </li>
            @endforeach
        </ul>

        <flux:pagination :paginator="$owners" />
    @endif
</x-page>
