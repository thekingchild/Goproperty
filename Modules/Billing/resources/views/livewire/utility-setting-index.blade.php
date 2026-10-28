{{--
    Units' utilities (/o/{organization}/utilities/units;
    Modules\Billing\Livewire\UtilitySettingIndex). Rows stack into cards on
    phones (UX_DESIGN.md § 6).
--}}
<x-page :heading="__('Units\' utilities')" :description="__('Which utilities each unit pays, at what amount and how often.')">
    <x-slot:actions>
        <x-list-export list="utility_settings" />
        @if ($canCreate)
            <flux:button variant="primary" icon="plus" :href="route('workspace.utilities.settings.create', $organization)" wire:navigate data-test="add-utility-setting">{{ __('Add to units') }}</flux:button>
        @endif
    </x-slot:actions>

    @include('billing::livewire.partials.utility-tabs', ['organization' => $organization, 'current' => 'settings', 'canSeeSettings' => true, 'canSeeReadings' => $canSeeReadings])

    @if (session('utility-status'))
        <flux:callout variant="success" icon="check-circle" :heading="session('utility-status')" role="status" data-test="status" />
    @endif

    <div class="grid gap-3 sm:grid-cols-3">
        <flux:select wire:model.live="building" :aria-label="__('Building')" size="sm" data-test="building-filter">
            <option value="">{{ __('All buildings') }}</option>
            @foreach ($buildings as $buildingId => $buildingName)
                <option value="{{ $buildingId }}">{{ $buildingName }}</option>
            @endforeach
        </flux:select>
        <flux:select wire:model.live="utility" :aria-label="__('Utility')" size="sm" data-test="utility-filter">
            <option value="">{{ __('All utilities') }}</option>
            @foreach ($utilities as $option)
                <option value="{{ $option->id }}">{{ $option->name }}{{ $option->active ? '' : ' ('.__('archived').')' }}</option>
            @endforeach
        </flux:select>
        <flux:select wire:model.live="status" :aria-label="__('Status')" size="sm" data-test="status-filter">
            <option value="">{{ __('On and off') }}</option>
            <option value="on">{{ __('On') }}</option>
            <option value="off">{{ __('Off') }}</option>
        </flux:select>
    </div>

    @if ($unit !== '')
        <div class="flex flex-wrap items-center gap-2">
            <flux:text>{{ __('Showing the utilities of one unit.') }}</flux:text>
            <flux:button size="sm" variant="ghost" wire:click="clearFilters" data-test="show-all-settings">{{ __('Show every unit') }}</flux:button>
        </div>
    @endif

    @if ($settings->isEmpty())
        @if ($filtered)
            <x-empty-state icon="magnifying-glass" :heading="__('Nothing matches')" :description="__('Clear the filters to see every unit\'s utilities.')">
                <x-slot:action>
                    <flux:button wire:click="clearFilters" data-test="clear-filters">{{ __('Clear filters') }}</flux:button>
                </x-slot:action>
            </x-empty-state>
        @else
            <x-empty-state icon="bolt" :heading="__('No unit pays a utility yet')" :description="__('Add a utility, then add it to the units that pay it.')" />
        @endif
    @else
        <ul class="divide-y divide-zinc-100 rounded-xl border border-zinc-200 bg-white dark:divide-zinc-800 dark:border-zinc-700 dark:bg-zinc-900" data-test="utility-settings-list">
            @foreach ($rows as $row)
                @php($setting = $row->setting)
                @php($type = $setting->utilityType)
                <li class="flex flex-col gap-2 p-4 sm:flex-row sm:items-center sm:justify-between" wire:key="utility-setting-{{ $setting->id }}" data-utility-setting="{{ $setting->id }}">
                    <div class="flex min-w-0 flex-col">
                        <span class="truncate font-medium text-zinc-900 dark:text-white">{{ $row->unitLabel }} · {{ $row->buildingName }}</span>
                        <span class="text-sm text-zinc-500 dark:text-zinc-400">
                            {{ $type->name }} · {{ $type->describeAmount($setting->amount()) }}@if ($setting->amount_kobo === null) <span data-test="default-amount-{{ $setting->id }}">({{ __('default') }})</span>@endif · {{ __($setting->billingCycle()->label()) }}
                        </span>
                        <span class="text-sm text-zinc-500 dark:text-zinc-400">
                            {{ __('from :date', ['date' => $setting->effective_from->format('d/m/Y')]) }}
                            @if ($type->isMetered())
                                · @if ($row->lastReading)
                                    <span data-test="last-reading-{{ $setting->id }}">{{ __('last reading :value on :date', ['value' => $row->lastReading->current_value->format(), 'date' => $row->lastReading->reading_date->format('d/m/Y')]) }}</span>
                                @else
                                    <span data-test="last-reading-{{ $setting->id }}">{{ __('starts at :value, no reading yet', ['value' => $setting->startingReading()->format()]) }}</span>
                                @endif
                            @endif
                        </span>
                    </div>

                    <div class="flex flex-wrap items-center gap-2">
                        @if ($setting->active && $type->active)
                            <flux:badge size="sm" color="green">{{ __('On') }}</flux:badge>
                        @elseif (! $type->active)
                            <flux:badge size="sm" color="zinc">{{ __('Utility archived') }}</flux:badge>
                        @else
                            <flux:badge size="sm" color="zinc">{{ __('Off') }}</flux:badge>
                        @endif
                        @if ($row->unitArchived)
                            <flux:badge size="sm" color="zinc">{{ __('Unit archived') }}</flux:badge>
                        @endif
                        @if ($type->isMetered() && $canSeeReadings)
                            <flux:button size="sm" variant="ghost" :href="route('workspace.utilities.readings.index', [$organization, 'meter' => $setting->id])" wire:navigate data-test="readings-{{ $setting->id }}">{{ __('Readings') }}</flux:button>
                        @endif
                        @if ($canRecord && ! $row->unitArchived && $setting->takesReadings())
                            <flux:button size="sm" :href="route('workspace.utilities.readings.create', [$organization, 'meter' => $setting->id])" wire:navigate data-test="record-reading-{{ $setting->id }}">{{ __('Record reading') }}</flux:button>
                        @endif
                        @if ($canCreate)
                            <flux:button size="sm" :href="route('workspace.utilities.settings.edit', [$organization, $setting->id])" wire:navigate data-test="edit-utility-setting-{{ $setting->id }}">{{ __('Edit') }}</flux:button>
                        @endif
                    </div>
                </li>
            @endforeach
        </ul>

        <flux:pagination :paginator="$settings" />
    @endif
</x-page>
