{{--
    Leases (/o/{organization}/leases; Modules\Leasing\Livewire\LeaseIndex).
    Rows stack into cards on phones (UX_DESIGN.md § 6). Buttons show only
    for what the member may do; every page and action checks its policy again.
--}}
<x-page :heading="__('Leases')" :description="__('Who rents which unit at :organization, on what terms.', ['organization' => $organization->name])">
    <x-slot:actions>
        <x-list-export list="leases" />
        @if ($canCreate)
            <flux:button variant="primary" icon="plus" :href="route('workspace.leases.create', $organization)" wire:navigate data-test="add-lease">{{ __('New lease') }}</flux:button>
        @endif
    </x-slot:actions>

    <div class="flex flex-col gap-3">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <div class="w-full sm:max-w-sm">
                <flux:input wire:model.live.debounce.400ms="search" icon="magnifying-glass" type="search" :placeholder="__('Search by contract number or tenant')" :aria-label="__('Search leases')" maxlength="100" data-test="lease-search" />
            </div>
            <flux:checkbox wire:model.live="ending" :label="__('Ending soon')" data-test="ending-filter" />
        </div>

        <div class="grid gap-3 sm:grid-cols-3">
            <flux:select wire:model.live="status" :aria-label="__('Status')" size="sm" data-test="status-filter">
                <option value="">{{ __('All statuses') }}</option>
                @foreach ($statuses as $option)
                    <option value="{{ $option->value }}">{{ __($option->label()) }}</option>
                @endforeach
            </flux:select>
            <flux:select wire:model.live="building" :aria-label="__('Building')" size="sm" data-test="building-filter">
                <option value="">{{ __('All buildings') }}</option>
                @foreach ($buildings as $buildingId => $buildingName)
                    <option value="{{ $buildingId }}">{{ $buildingName }}</option>
                @endforeach
            </flux:select>
            <flux:select wire:model.live="cycle" :aria-label="__('Billing cycle')" size="sm">
                <option value="">{{ __('Any billing cycle') }}</option>
                @foreach ($cycles as $option)
                    <option value="{{ $option->value }}">{{ __($option->label()) }}</option>
                @endforeach
            </flux:select>
        </div>
    </div>

    @if ($leases->isEmpty())
        @if ($filtered)
            <x-empty-state icon="magnifying-glass" :heading="__('No lease matches')" :description="__('Check the spelling, or clear the filters to see every lease.')">
                <x-slot:action>
                    <flux:button wire:click="clearFilters" data-test="clear-filters">{{ __('Clear filters') }}</flux:button>
                </x-slot:action>
            </x-empty-state>
        @else
            <x-empty-state icon="document-text" :heading="__('No leases yet')" :description="__('A lease lets a unit to a tenant. Draft one, and the unit is held for the tenant until you activate it.')">
                @if ($canCreate)
                    <x-slot:action>
                        <flux:button variant="primary" icon="plus" :href="route('workspace.leases.create', $organization)" wire:navigate>{{ __('New lease') }}</flux:button>
                    </x-slot:action>
                @endif
            </x-empty-state>
        @endif
    @else
        <ul class="divide-y divide-zinc-100 rounded-xl border border-zinc-200 bg-white dark:divide-zinc-800 dark:border-zinc-700 dark:bg-zinc-900" data-test="leases-list">
            @foreach ($rows as $row)
                @php($lease = $row->lease)
                <li class="flex flex-col gap-2 p-4 sm:flex-row sm:items-center sm:justify-between" wire:key="lease-{{ $lease->id }}" data-lease="{{ $lease->id }}">
                    <div class="flex min-w-0 flex-col">
                        <a href="{{ route('workspace.leases.show', [$organization, $lease->id]) }}" wire:navigate class="truncate font-medium text-zinc-900 hover:underline dark:text-white">{{ $row->tenantName }}</a>
                        <span class="truncate text-sm text-zinc-500 dark:text-zinc-400">{{ $row->unitLabel }} · {{ $row->buildingName }}</span>
                        <span class="text-sm text-zinc-500 dark:text-zinc-400">
                            {{ $lease->contract_number ?? __('Draft') }} · {{ $lease->start_date->format('d/m/Y') }}–{{ $lease->end_date->format('d/m/Y') }} · {{ __(':amount :cycle', ['amount' => $lease->rent_amount_kobo->format(), 'cycle' => mb_strtolower(__($lease->billing_cycle->label()))]) }}
                        </span>
                    </div>

                    <div class="flex flex-wrap items-center gap-2">
                        @if ($lease->ending_soon_flagged_at !== null && $lease->status === \Modules\Leasing\Enums\LeaseStatus::Active)
                            <flux:badge size="sm" color="amber" data-test="ending-{{ $lease->id }}">{{ __('Ending soon') }}</flux:badge>
                        @endif
                        <flux:badge size="sm" :color="$lease->status->color()" data-test="status-{{ $lease->id }}">{{ __($lease->status->label()) }}</flux:badge>
                    </div>
                </li>
            @endforeach
        </ul>

        <flux:pagination :paginator="$leases" />
    @endif
</x-page>
