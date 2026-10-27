{{--
    Tenants (/o/{organization}/tenants; Modules\Tenants\Livewire\TenantIndex).
    Rows stack into cards on phones (UX_DESIGN.md § 6). Buttons show only
    for what the member may do; every action checks its policy again.
--}}
<x-page :heading="__('Tenants')" :description="__('The people who rent from :organization.', ['organization' => $organization->name])">
    <x-slot:actions>
        <x-list-export list="tenants" />
        @if ($canManage)
            <flux:button variant="primary" icon="plus" :href="route('workspace.tenants.create', $organization)" wire:navigate data-test="add-tenant">{{ __('Add tenant') }}</flux:button>
        @endif
    </x-slot:actions>

    @if ($notice)
        <flux:callout variant="success" icon="check-circle" :heading="$notice" role="status" data-test="tenants-notice" />
    @endif

    @error('archive')
        <flux:callout variant="warning" icon="exclamation-triangle" :heading="$message" role="alert" data-test="tenants-warning" />
    @enderror

    <div class="flex flex-col gap-3">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <div class="w-full sm:max-w-sm">
                <flux:input wire:model.live.debounce.400ms="search" icon="magnifying-glass" type="search" :placeholder="__('Search by name, phone or email')" :aria-label="__('Search tenants')" maxlength="100" data-test="tenant-search" />
            </div>
            <div class="flex gap-1" role="group" aria-label="{{ __('Show') }}">
                <flux:button size="sm" :variant="$archived ? 'ghost' : 'filled'" wire:click="$set('archived', false)" :aria-pressed="$archived ? 'false' : 'true'">{{ __('Active') }}</flux:button>
                <flux:button size="sm" :variant="$archived ? 'filled' : 'ghost'" wire:click="$set('archived', true)" :aria-pressed="$archived ? 'true' : 'false'" data-test="show-archived">{{ __('Archived') }}</flux:button>
            </div>
        </div>

        <div class="grid gap-3 sm:grid-cols-2 lg:max-w-xl">
            <flux:select wire:model.live="kyc" :aria-label="__('KYC status')" size="sm" data-test="kyc-filter">
                <option value="">{{ __('Any KYC status') }}</option>
                @foreach ($statuses as $option)
                    <option value="{{ $option->value }}">{{ __($option->label()) }}</option>
                @endforeach
            </flux:select>
            <flux:select wire:model.live="portal" :aria-label="__('Portal access')" size="sm" data-test="portal-filter">
                <option value="">{{ __('With or without portal access') }}</option>
                <option value="yes">{{ __('Has portal access') }}</option>
                <option value="no">{{ __('No portal access yet') }}</option>
            </flux:select>
        </div>
    </div>

    @if ($tenants->isEmpty())
        @if ($filtered)
            <x-empty-state icon="magnifying-glass" :heading="trim($search) !== '' ? __('No tenant matches “:search”', ['search' => $search]) : __('No tenant matches')" :description="__('Check the spelling, search by phone number or email instead, or clear the filters.')">
                <x-slot:action>
                    <flux:button wire:click="clearFilters" data-test="clear-filters">{{ __('Clear filters') }}</flux:button>
                </x-slot:action>
            </x-empty-state>
        @elseif ($archived)
            <x-empty-state icon="archive-box" :heading="__('No archived tenants')" :description="__('Tenants you archive are kept here, and you can restore them at any time.')" />
        @else
            <x-empty-state icon="users" :heading="__('No tenants yet')" :description="__('Add the people who rent your units, or import your tenant list from Excel. Nobody is contacted until you invite them.')">
                @if ($canManage)
                    <x-slot:action>
                        <flux:button variant="primary" icon="plus" :href="route('workspace.tenants.create', $organization)" wire:navigate>{{ __('Add tenant') }}</flux:button>
                    </x-slot:action>
                @endif
            </x-empty-state>
        @endif
    @else
        <ul class="divide-y divide-zinc-100 rounded-xl border border-zinc-200 bg-white dark:divide-zinc-800 dark:border-zinc-700 dark:bg-zinc-900" data-test="tenants-list">
            @foreach ($tenants as $tenant)
                <li class="flex flex-col gap-3 p-4 sm:flex-row sm:items-center sm:justify-between" wire:key="tenant-{{ $tenant->id }}" data-tenant="{{ $tenant->id }}">
                    <div class="flex min-w-0 items-center gap-3">
                        <flux:avatar :name="$tenant->fullName()" size="sm" />
                        <div class="flex min-w-0 flex-col">
                            <a href="{{ route('workspace.tenants.show', [$organization, $tenant]) }}" wire:navigate class="truncate font-medium text-zinc-900 hover:underline dark:text-white">{{ $tenant->fullName() }}</a>
                            <span class="truncate text-sm text-zinc-500 dark:text-zinc-400">
                                {{ collect([$tenant->phone, $tenant->email])->filter()->join(' · ') }}
                            </span>
                        </div>
                    </div>

                    <div class="flex flex-wrap items-center gap-2">
                        <flux:badge size="sm" :color="match ($tenant->kyc_status) { \Modules\Tenants\Enums\KycStatus::Verified => 'green', \Modules\Tenants\Enums\KycStatus::Pending => 'amber', default => 'zinc' }">{{ __($tenant->kyc_status->label()) }}</flux:badge>
                        @if ($tenant->user_id !== null)
                            <flux:badge size="sm" color="blue" icon="device-phone-mobile">{{ __('Portal') }}</flux:badge>
                        @endif
                        @if ($canManage)
                            @if ($archived)
                                <flux:button size="sm" wire:click="restore({{ $tenant->id }})" data-test="restore-{{ $tenant->id }}">{{ __('Restore') }}</flux:button>
                            @else
                                <flux:button size="sm" :href="route('workspace.tenants.edit', [$organization, $tenant])" wire:navigate>{{ __('Edit') }}</flux:button>
                                <flux:button size="sm" variant="danger" wire:click="archive({{ $tenant->id }})" wire:confirm="{{ __('Archive :name? The record is kept and you can restore it later.', ['name' => $tenant->fullName()]) }}" data-test="archive-{{ $tenant->id }}">{{ __('Archive') }}</flux:button>
                            @endif
                        @endif
                    </div>
                </li>
            @endforeach
        </ul>

        <flux:pagination :paginator="$tenants" />
    @endif
</x-page>
