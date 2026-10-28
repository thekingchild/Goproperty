{{--
    Utilities (/o/{organization}/utilities; Modules\Billing\Livewire\UtilityTypeIndex).
    Rows stack into cards on phones (UX_DESIGN.md § 6). Every action checks
    UtilityTypePolicy again.
--}}
<x-page :heading="__('Utilities')" :description="__('Utilities :organization charges tenants for, such as security, water and electricity.', ['organization' => $organization->name])">
    <x-slot:actions>
        <x-list-export list="utility_types" />
        @if ($canCreate)
            <flux:button variant="primary" icon="plus" :href="route('workspace.utilities.types.create', $organization)" wire:navigate data-test="add-utility-type">{{ __('Add utility') }}</flux:button>
        @endif
    </x-slot:actions>

    @include('billing::livewire.partials.utility-tabs', ['organization' => $organization, 'current' => 'types', 'canSeeSettings' => $canSeeSettings, 'canSeeReadings' => $canSeeReadings])

    @if ($notice ?? session('utility-status'))
        <flux:callout variant="success" icon="check-circle" :heading="$notice ?? session('utility-status')" role="status" data-test="status" />
    @endif

    <div class="flex flex-col gap-3">
        <div class="w-full sm:max-w-sm">
            <flux:input wire:model.live.debounce.400ms="search" icon="magnifying-glass" type="search" :placeholder="__('Search by name or code')" :aria-label="__('Search utilities')" maxlength="100" data-test="utility-search" />
        </div>

        <div class="grid gap-3 sm:grid-cols-2">
            <flux:select wire:model.live="status" :aria-label="__('Status')" size="sm" data-test="status-filter">
                <option value="">{{ __('Active and archived') }}</option>
                <option value="active">{{ __('Active') }}</option>
                <option value="archived">{{ __('Archived') }}</option>
            </flux:select>
            <flux:select wire:model.live="charging" :aria-label="__('Charging')" size="sm" data-test="charging-filter">
                <option value="">{{ __('Flat and metered') }}</option>
                @foreach ($chargingModes as $mode)
                    <option value="{{ $mode->value }}">{{ __($mode->shortLabel()) }}</option>
                @endforeach
            </flux:select>
        </div>
    </div>

    @if ($types->isEmpty())
        @if ($filtered)
            <x-empty-state icon="magnifying-glass" :heading="__('No utility matches')" :description="__('Check the spelling, or clear the filters to see every utility.')">
                <x-slot:action>
                    <flux:button wire:click="clearFilters" data-test="clear-filters">{{ __('Clear filters') }}</flux:button>
                </x-slot:action>
            </x-empty-state>
        @else
            <x-empty-state icon="bolt" :heading="__('No utilities yet')" :description="__('Add the utilities you charge, such as a security levy or metered electricity, then choose which units pay them.')" />
        @endif
    @else
        <ul class="divide-y divide-zinc-100 rounded-xl border border-zinc-200 bg-white dark:divide-zinc-800 dark:border-zinc-700 dark:bg-zinc-900" data-test="utility-types-list">
            @foreach ($types as $type)
                <li class="flex flex-col gap-2 p-4 sm:flex-row sm:items-center sm:justify-between" wire:key="utility-type-{{ $type->id }}" data-utility-type="{{ $type->id }}">
                    <div class="flex min-w-0 flex-col">
                        <span class="truncate font-medium text-zinc-900 dark:text-white">{{ $type->name }} <span class="text-sm font-normal text-zinc-500 dark:text-zinc-400">· {{ $type->code }}</span></span>
                        <span class="text-sm text-zinc-500 dark:text-zinc-400">{{ __($type->charging_mode->shortLabel()) }} · {{ $type->describeAmount() }} · {{ __($type->default_cycle->label()) }}</span>
                        <span class="text-sm text-zinc-500 dark:text-zinc-400" data-test="units-charged-{{ $type->id }}">{{ trans_choice(':count unit charged|:count units charged', (int) $type->units_charged, ['count' => (int) $type->units_charged]) }}</span>
                    </div>

                    <div class="flex flex-wrap items-center gap-2">
                        @if ($type->active)
                            <flux:badge size="sm" color="green">{{ __('Active') }}</flux:badge>
                        @else
                            <flux:badge size="sm" color="zinc">{{ __('Archived') }}</flux:badge>
                        @endif
                        @can('update', $type)
                            <flux:button size="sm" :href="route('workspace.utilities.types.edit', [$organization, $type->id])" wire:navigate data-test="edit-utility-type-{{ $type->id }}">{{ __('Edit') }}</flux:button>
                        @endcan
                        @can('delete', $type)
                            <flux:button size="sm" variant="ghost" wire:click="archive({{ $type->id }})" wire:confirm="{{ __('Archive :name? Units stop being charged it; their settings and readings are kept.', ['name' => $type->name]) }}" data-test="archive-utility-type-{{ $type->id }}">{{ __('Archive') }}</flux:button>
                        @endcan
                        @can('restore', $type)
                            <flux:button size="sm" variant="ghost" wire:click="restore({{ $type->id }})" data-test="restore-utility-type-{{ $type->id }}">{{ __('Restore') }}</flux:button>
                        @endcan
                    </div>
                </li>
            @endforeach
        </ul>

        <flux:pagination :paginator="$types" />
    @endif
</x-page>
