{{--
    Bills (/o/{organization}/bills; Modules\Billing\Livewire\BillIndex).
    Rows stack into cards on phones (UX_DESIGN.md § 6). Bills are generated
    from leases each night; every page and action checks its policy.
--}}
<x-page :heading="__('Bills')" :description="__('Rent and other bills :organization has issued to tenants.', ['organization' => $organization->name])">
    <x-slot:actions>
        <x-list-export list="bills" />
    </x-slot:actions>

    <div class="flex flex-col gap-3">
        <div class="w-full sm:max-w-sm">
            <flux:input wire:model.live.debounce.400ms="search" icon="magnifying-glass" type="search" :placeholder="__('Search by bill number, reference or tenant')" :aria-label="__('Search bills')" maxlength="100" data-test="bill-search" />
        </div>

        <div class="grid gap-3 sm:grid-cols-3">
            <flux:select wire:model.live="status" :aria-label="__('Status')" size="sm" data-test="status-filter">
                <option value="">{{ __('All statuses') }}</option>
                @foreach ($statuses as $option)
                    <option value="{{ $option->value }}">{{ __($option->label()) }}</option>
                @endforeach
            </flux:select>
            <flux:select wire:model.live="type" :aria-label="__('Type')" size="sm" data-test="type-filter">
                <option value="">{{ __('All types') }}</option>
                @foreach ($types as $option)
                    <option value="{{ $option->value }}">{{ __($option->label()) }}</option>
                @endforeach
            </flux:select>
            <flux:select wire:model.live="building" :aria-label="__('Building')" size="sm" data-test="building-filter">
                <option value="">{{ __('All buildings') }}</option>
                @foreach ($buildings as $buildingId => $buildingName)
                    <option value="{{ $buildingId }}">{{ $buildingName }}</option>
                @endforeach
            </flux:select>
        </div>

        @if ($lease !== '' || $tenant !== '')
            <div class="flex flex-wrap items-center gap-2">
                <flux:text>{{ $lease !== '' ? __('Showing the bills of one lease.') : __('Showing the bills of one tenant.') }}</flux:text>
                <flux:button size="sm" variant="ghost" wire:click="clearFilters" data-test="show-all-bills">{{ __('Show every bill') }}</flux:button>
            </div>
        @endif
    </div>

    @if ($bills->isEmpty())
        @if ($filtered)
            <x-empty-state icon="magnifying-glass" :heading="__('No bill matches')" :description="__('Check the spelling, or clear the filters to see every bill.')">
                <x-slot:action>
                    <flux:button wire:click="clearFilters" data-test="clear-filters">{{ __('Clear filters') }}</flux:button>
                </x-slot:action>
            </x-empty-state>
        @else
            <x-empty-state icon="banknotes" :heading="__('No bills yet')" :description="__('Each active lease is billed automatically a few days before its next period starts.')" />
        @endif
    @else
        <ul class="divide-y divide-zinc-100 rounded-xl border border-zinc-200 bg-white dark:divide-zinc-800 dark:border-zinc-700 dark:bg-zinc-900" data-test="bills-list">
            @foreach ($rows as $row)
                @php($bill = $row->bill)
                <li class="flex flex-col gap-2 p-4 sm:flex-row sm:items-center sm:justify-between" wire:key="bill-{{ $bill->id }}" data-bill="{{ $bill->id }}">
                    <div class="flex min-w-0 flex-col">
                        <a href="{{ route('workspace.bills.show', [$organization, $bill->id]) }}" wire:navigate class="truncate font-medium text-zinc-900 hover:underline dark:text-white">{{ $bill->number }} · {{ $row->tenantName }}</a>
                        @if ($row->unitLabel !== null)
                            <span class="truncate text-sm text-zinc-500 dark:text-zinc-400">{{ $row->unitLabel }} · {{ $row->buildingName }}</span>
                        @endif
                        <span class="text-sm text-zinc-500 dark:text-zinc-400">{{ $bill->description }} · {{ __('due :date', ['date' => $bill->due_date->format('d/m/Y')]) }}</span>
                    </div>

                    <div class="flex flex-wrap items-center gap-2">
                        <span class="font-medium text-zinc-900 dark:text-white" data-test="amount-{{ $bill->id }}">{{ $bill->amount_kobo->format() }}</span>
                        <flux:badge size="sm" :color="$bill->status->color()" data-test="status-{{ $bill->id }}">{{ __($bill->status->label()) }}</flux:badge>
                    </div>
                </li>
            @endforeach
        </ul>

        <flux:pagination :paginator="$bills" />
    @endif
</x-page>
