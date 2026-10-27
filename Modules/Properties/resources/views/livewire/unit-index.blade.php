{{--
    Units (/o/{organization}/units; Modules\Properties\Livewire\UnitIndex).
    Rows stack into cards on phones (UX_DESIGN.md § 6). The edit link shows
    only for what the member may do; the unit's page checks its policy again.
--}}
<x-page :heading="__('Units')" :description="__('Every flat, shop and office in the buildings :organization manages.', ['organization' => $organization->name])">
    <div class="flex flex-col gap-3">
        <div class="w-full sm:max-w-sm">
            <flux:input wire:model.live.debounce.400ms="search" icon="magnifying-glass" type="search" :placeholder="__('Search by unit or building')" :aria-label="__('Search units')" maxlength="100" data-test="unit-search" />
        </div>

        <div class="grid gap-3 sm:grid-cols-3">
            <flux:select wire:model.live="building" :aria-label="__('Building')" size="sm" data-test="building-filter">
                <option value="">{{ __('All buildings') }}</option>
                @foreach ($buildings as $buildingId => $buildingName)
                    <option value="{{ $buildingId }}">{{ $buildingName }}</option>
                @endforeach
            </flux:select>
            <flux:select wire:model.live="status" :aria-label="__('Status')" size="sm">
                <option value="">{{ __('All statuses') }}</option>
                @foreach ($statuses as $option)
                    <option value="{{ $option->value }}">{{ __($option->label()) }}</option>
                @endforeach
            </flux:select>
            <flux:select wire:model.live="type" :aria-label="__('Unit type')" size="sm">
                <option value="">{{ __('All types') }}</option>
                @foreach ($types as $option)
                    <option value="{{ $option->value }}">{{ __($option->label()) }}</option>
                @endforeach
            </flux:select>
        </div>
    </div>

    @if ($units->isEmpty())
        @if ($filtered)
            <x-empty-state icon="magnifying-glass" :heading="__('No unit matches')" :description="__('Check the spelling, or clear the filters to see every unit.')">
                <x-slot:action>
                    <flux:button wire:click="clearFilters" data-test="clear-filters">{{ __('Clear filters') }}</flux:button>
                </x-slot:action>
            </x-empty-state>
        @else
            <x-empty-state icon="home-modern" :heading="__('No units yet')" :description="__('Units are added on their building\'s page, one at a time or several at once.')">
                <x-slot:action>
                    <flux:button :href="route('workspace.buildings.index', $organization)" wire:navigate>{{ __('Go to buildings') }}</flux:button>
                </x-slot:action>
            </x-empty-state>
        @endif
    @else
        <ul class="divide-y divide-zinc-100 rounded-xl border border-zinc-200 bg-white dark:divide-zinc-800 dark:border-zinc-700 dark:bg-zinc-900" data-test="units-list">
            @foreach ($units as $unit)
                <li class="flex flex-col gap-2 p-4 sm:flex-row sm:items-center sm:justify-between" wire:key="unit-{{ $unit->id }}" data-unit="{{ $unit->id }}">
                    <div class="flex min-w-0 flex-col">
                        <span class="truncate font-medium text-zinc-900 dark:text-white">{{ $unit->unit_label }}</span>
                        <a href="{{ route('workspace.buildings.show', [$organization, $unit->property]) }}" wire:navigate class="truncate text-sm text-zinc-500 hover:underline dark:text-zinc-400">
                            {{ $unit->property->name }}@if ($unit->property->isArchived()) ({{ __('archived building') }})@endif
                        </a>
                        <span class="text-sm text-zinc-500 dark:text-zinc-400">
                            {{ __($unit->unit_type->label()) }}@if ($unit->bedrooms !== null) · {{ trans_choice(':count bedroom|:count bedrooms', $unit->bedrooms, ['count' => $unit->bedrooms]) }}@endif · {{ __(':amount a year', ['amount' => $unit->base_rent_kobo->format()]) }}
                        </span>
                    </div>

                    <div class="flex flex-wrap items-center gap-2">
                        <flux:badge size="sm" :color="$unit->status->color()" data-test="status-{{ $unit->id }}">{{ __($unit->status->label()) }}</flux:badge>
                        @if ($canEdit && ! $unit->property->isArchived())
                            <flux:button size="sm" :href="route('workspace.units.edit', [$organization, $unit->property, $unit->id])" wire:navigate data-test="edit-unit-{{ $unit->id }}">{{ __('Edit') }}</flux:button>
                        @endif
                    </div>
                </li>
            @endforeach
        </ul>

        <flux:pagination :paginator="$units" />
    @endif
</x-page>
