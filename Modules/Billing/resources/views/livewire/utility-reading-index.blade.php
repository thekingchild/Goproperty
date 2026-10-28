{{--
    Meter readings (/o/{organization}/utilities/readings;
    Modules\Billing\Livewire\UtilityReadingIndex). Rows stack into cards on
    phones (UX_DESIGN.md § 6).
--}}
<x-page :heading="__('Meter readings')" :description="__('Readings of units\' metered utilities, newest first.')">
    <x-slot:actions>
        <x-list-export list="utility_readings" />
        @if ($canRecord)
            <flux:button variant="primary" icon="plus" :href="route('workspace.utilities.readings.create', $meter !== '' ? [$organization, 'meter' => $meter] : $organization)" wire:navigate data-test="record-reading">{{ __('Record a reading') }}</flux:button>
        @endif
    </x-slot:actions>

    @include('billing::livewire.partials.utility-tabs', ['organization' => $organization, 'current' => 'readings', 'canSeeSettings' => $canSeeSettings, 'canSeeReadings' => true])

    @if (session('utility-status'))
        <flux:callout variant="success" icon="check-circle" :heading="session('utility-status')" role="status" data-test="status" />
    @endif

    <div class="grid gap-3 sm:grid-cols-2">
        <flux:select wire:model.live="building" :aria-label="__('Building')" size="sm" data-test="building-filter">
            <option value="">{{ __('All buildings') }}</option>
            @foreach ($buildings as $buildingId => $buildingName)
                <option value="{{ $buildingId }}">{{ $buildingName }}</option>
            @endforeach
        </flux:select>
        <flux:select wire:model.live="utility" :aria-label="__('Utility')" size="sm" data-test="utility-filter">
            <option value="">{{ __('All metered utilities') }}</option>
            @foreach ($utilities as $option)
                <option value="{{ $option->id }}">{{ $option->name }}{{ $option->active ? '' : ' ('.__('archived').')' }}</option>
            @endforeach
        </flux:select>
    </div>

    @if ($meter !== '')
        <div class="flex flex-wrap items-center gap-2">
            <flux:text>{{ __('Showing the readings of one meter.') }}</flux:text>
            <flux:button size="sm" variant="ghost" wire:click="clearFilters" data-test="show-all-readings">{{ __('Show every reading') }}</flux:button>
        </div>
    @endif

    @if ($readings->isEmpty())
        @if ($filtered)
            <x-empty-state icon="magnifying-glass" :heading="__('No reading matches')" :description="__('Clear the filters to see every reading.')">
                <x-slot:action>
                    <flux:button wire:click="clearFilters" data-test="clear-filters">{{ __('Clear filters') }}</flux:button>
                </x-slot:action>
            </x-empty-state>
        @else
            <x-empty-state icon="bolt" :heading="__('No readings yet')" :description="__('Record the meters of units that pay a metered utility.')" />
        @endif
    @else
        <ul class="divide-y divide-zinc-100 rounded-xl border border-zinc-200 bg-white dark:divide-zinc-800 dark:border-zinc-700 dark:bg-zinc-900" data-test="utility-readings-list">
            @foreach ($rows as $row)
                @php($reading = $row->reading)
                @php($type = $reading->setting->utilityType)
                <li class="flex flex-col gap-2 p-4 sm:flex-row sm:items-center sm:justify-between" wire:key="utility-reading-{{ $reading->id }}" data-utility-reading="{{ $reading->id }}">
                    <div class="flex min-w-0 flex-col">
                        <span class="truncate font-medium text-zinc-900 dark:text-white">{{ $reading->reading_date->format('d/m/Y') }} · {{ $row->unitLabel }} · {{ $row->buildingName }}</span>
                        <span class="text-sm text-zinc-500 dark:text-zinc-400" data-test="reading-values-{{ $reading->id }}">
                            {{ $type->name }}: {{ $reading->previous_value->format() }} → {{ $reading->current_value->format() }} · {{ __('used :amount :unit', ['amount' => $reading->consumption->format(), 'unit' => __(($type->unit_of_measure ?? \Modules\Billing\Enums\MeterUnit::Unit)->label())]) }}
                        </span>
                        <span class="text-sm text-zinc-500 dark:text-zinc-400">
                            {{ $row->recordedBy ? __('Recorded by :name', ['name' => $row->recordedBy]) : __('Recorded') }}
                            @if ($reading->note) · {{ $reading->note }} @endif
                        </span>
                    </div>

                    <div class="flex flex-wrap items-center gap-2">
                        @if ($reading->meter_replaced)
                            <flux:badge size="sm" color="amber" data-test="meter-replaced-{{ $reading->id }}">{{ __('Meter replaced') }}</flux:badge>
                        @endif
                        @if ($reading->isBilled())
                            {{-- The bill page checks BillPolicy::view (404 outside the member's buildings). --}}
                            <a href="{{ route('workspace.bills.show', [$organization, $reading->bill_id]) }}" wire:navigate data-test="reading-bill-{{ $reading->id }}"><flux:badge size="sm" color="green">{{ __('Billed') }}</flux:badge></a>
                        @else
                            <flux:badge size="sm" color="zinc">{{ __('Not billed yet') }}</flux:badge>
                        @endif
                        @if ($reading->getMedia(\Modules\Billing\Models\UtilityReading::PHOTO_COLLECTION)->isNotEmpty())
                            {{-- The photo link checks UtilityReadingPolicy::viewPhoto. --}}
                            <flux:button size="sm" variant="ghost" icon="camera" :href="route('workspace.utilities.readings.photo', [$organization, $reading->id])" target="_blank" data-test="reading-photo-{{ $reading->id }}">{{ __('Photo') }}</flux:button>
                        @endif
                        @if (! $reading->isBilled() && in_array($reading->id, $correctable, true))
                            {{-- The form checks UtilityReadingPolicy::update. --}}
                            <flux:button size="sm" :href="route('workspace.utilities.readings.edit', [$organization, $reading->id])" wire:navigate data-test="correct-reading-{{ $reading->id }}">{{ __('Correct') }}</flux:button>
                        @endif
                    </div>
                </li>
            @endforeach
        </ul>

        <flux:pagination :paginator="$readings" />
    @endif
</x-page>
