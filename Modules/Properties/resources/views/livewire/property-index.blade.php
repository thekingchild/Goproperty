{{--
    Buildings (/o/{organization}/buildings; Modules\Properties\Livewire\PropertyIndex).
    Rows stack into cards on phones (UX_DESIGN.md § 6). Buttons show only
    for what the member may do; every action checks its policy again.
--}}
<x-page :heading="__('Buildings')" :description="__('The buildings :organization manages.', ['organization' => $organization->name])">
    @if ($canAdd)
        <x-slot:actions>
            <flux:button variant="primary" icon="plus" :href="route('workspace.buildings.create', $organization)" wire:navigate data-test="add-building">{{ __('Add building') }}</flux:button>
        </x-slot:actions>
    @endif

    @error('archive')
        <flux:callout variant="warning" icon="exclamation-triangle" :heading="$message" role="alert" data-test="buildings-warning" />
    @else
        @if ($notice)
            <flux:callout variant="success" icon="check-circle" :heading="$notice" role="status" data-test="buildings-notice" />
        @endif
    @enderror

    <div class="flex flex-col gap-3">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <div class="w-full sm:max-w-sm">
                <flux:input wire:model.live.debounce.400ms="search" icon="magnifying-glass" type="search" :placeholder="__('Search by name, address or landmark')" :aria-label="__('Search buildings')" maxlength="100" />
            </div>
            <div class="flex gap-1" role="group" aria-label="{{ __('Show') }}">
                <flux:button size="sm" :variant="$archived ? 'ghost' : 'filled'" wire:click="$set('archived', false)" :aria-pressed="$archived ? 'false' : 'true'">{{ __('Active') }}</flux:button>
                <flux:button size="sm" :variant="$archived ? 'filled' : 'ghost'" wire:click="$set('archived', true)" :aria-pressed="$archived ? 'true' : 'false'" data-test="show-archived">{{ __('Archived') }}</flux:button>
            </div>
        </div>

        <div class="grid gap-3 sm:grid-cols-3">
            <flux:select wire:model.live="type" :aria-label="__('Building type')" size="sm">
                <option value="">{{ __('All types') }}</option>
                @foreach ($types as $option)
                    <option value="{{ $option->value }}">{{ __($option->label()) }}</option>
                @endforeach
            </flux:select>
            <flux:select wire:model.live="state" :aria-label="__('State')" size="sm">
                <option value="">{{ __('All states') }}</option>
                @foreach ($states as $stateName)
                    <option value="{{ $stateName }}">{{ $stateName }}</option>
                @endforeach
            </flux:select>
            <flux:select wire:model.live="owner" :aria-label="__('Owner')" size="sm">
                <option value="">{{ __('All owners') }}</option>
                @foreach ($owners as $ownerId => $ownerName)
                    <option value="{{ $ownerId }}">{{ $ownerName }}</option>
                @endforeach
            </flux:select>
        </div>
    </div>

    @if ($properties->isEmpty())
        @if ($filtered)
            <x-empty-state icon="magnifying-glass" :heading="__('No building matches')" :description="__('Check the spelling, or clear the filters to see every building.')">
                <x-slot:action>
                    <flux:button wire:click="clearFilters">{{ __('Clear filters') }}</flux:button>
                </x-slot:action>
            </x-empty-state>
        @elseif ($archived)
            <x-empty-state icon="archive-box" :heading="__('No archived buildings')" :description="__('Buildings you archive are kept here with their history, and you can restore them at any time.')" />
        @else
            <x-empty-state icon="building-office-2" :heading="__('No buildings yet')" :description="__('Add the buildings you manage, then their flats, shops and offices.')">
                @if ($canAdd)
                    <x-slot:action>
                        <flux:button variant="primary" icon="plus" :href="route('workspace.buildings.create', $organization)" wire:navigate>{{ __('Add building') }}</flux:button>
                    </x-slot:action>
                @endif
            </x-empty-state>
        @endif
    @else
        <ul class="divide-y divide-zinc-100 rounded-xl border border-zinc-200 bg-white dark:divide-zinc-800 dark:border-zinc-700 dark:bg-zinc-900" data-test="buildings-list">
            @foreach ($properties as $property)
                <li class="flex flex-col gap-3 p-4 sm:flex-row sm:items-center sm:justify-between" wire:key="building-{{ $property->id }}" data-building="{{ $property->id }}">
                    <div class="flex min-w-0 items-center gap-3">
                        @if (isset($thumbnails[$property->id]))
                            <img src="{{ $thumbnails[$property->id] }}" alt="" class="size-12 shrink-0 rounded-lg object-cover" loading="lazy" data-test="thumbnail-{{ $property->id }}" />
                        @else
                            <div class="flex size-12 shrink-0 items-center justify-center rounded-lg bg-zinc-100 text-zinc-400 dark:bg-zinc-800">
                                <flux:icon name="building-office-2" variant="outline" />
                            </div>
                        @endif
                        <div class="flex min-w-0 flex-col">
                            <a href="{{ route('workspace.buildings.show', [$organization, $property]) }}" wire:navigate class="truncate font-medium text-zinc-900 hover:underline dark:text-white">{{ $property->name }}</a>
                            <span class="truncate text-sm text-zinc-500 dark:text-zinc-400">
                                {{ collect([$property->address, $property->lga, $property->state])->filter()->join(', ') }}
                            </span>
                            <span class="truncate text-sm text-zinc-500 dark:text-zinc-400">
                                {{ $property->owner ? __('Owner: :name', ['name' => $property->owner->display_name]) : __('No owner on file') }}
                            </span>
                        </div>
                    </div>

                    <div class="flex flex-wrap items-center gap-2">
                        <flux:badge size="sm" color="zinc">{{ __($property->property_type->label()) }}</flux:badge>
                        <flux:badge size="sm" color="zinc" data-test="unit-count-{{ $property->id }}">{{ trans_choice(':count unit|:count units', $property->units_count, ['count' => $property->units_count]) }}@if ($property->units_count > 0) · {{ __(':count occupied', ['count' => $property->occupied_units_count]) }}@endif</flux:badge>
                        @if ($canRestore)
                            <flux:button size="sm" wire:click="restore({{ $property->id }})" data-test="restore-{{ $property->id }}">{{ __('Restore') }}</flux:button>
                        @elseif ($canArchive)
                            <flux:button size="sm" variant="danger" wire:click="archive({{ $property->id }})" wire:confirm="{{ __('Archive :name? It is kept with its history, its units stop counting towards your bill, and you can restore it later.', ['name' => $property->name]) }}" data-test="archive-{{ $property->id }}">{{ __('Archive') }}</flux:button>
                        @endif
                    </div>
                </li>
            @endforeach
        </ul>

        <flux:pagination :paginator="$properties" />
    @endif
</x-page>
