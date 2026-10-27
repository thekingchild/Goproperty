{{--
    A building's page (Modules\Properties\Livewire\PropertyShow).
    Photos are shown through signed, expiring links only.
--}}
<x-page :heading="$property->name" :description="collect([$property->address, $property->lga, $property->state])->filter()->join(', ')">
    <x-slot:actions>
        <flux:button icon="arrow-left" :href="route('workspace.buildings.index', $organization)" wire:navigate>{{ __('All buildings') }}</flux:button>
        @if ($canEdit)
            <flux:button icon="pencil-square" :href="route('workspace.buildings.edit', [$organization, $property])" wire:navigate data-test="edit-building">{{ __('Edit') }}</flux:button>
        @endif
        @if ($canArchive)
            <flux:button variant="danger" wire:click="archive" wire:confirm="{{ __('Archive :name? It is kept with its history, its units stop counting towards your bill, and you can restore it later.', ['name' => $property->name]) }}" data-test="archive-building">{{ __('Archive') }}</flux:button>
        @endif
        @if ($canRestore)
            <flux:button variant="primary" wire:click="restore" data-test="restore-building">{{ __('Restore') }}</flux:button>
        @endif
    </x-slot:actions>

    @error('archive')
        <flux:callout variant="warning" icon="exclamation-triangle" :heading="$message" role="alert" data-test="building-warning" />
    @else
        @if ($notice)
            <flux:callout variant="success" icon="check-circle" :heading="$notice" role="status" data-test="building-notice" />
        @elseif ($flash)
            <flux:callout variant="success" icon="check-circle" :heading="$flash" role="status" data-test="building-notice" />
        @endif
    @enderror

    @if ($property->isArchived())
        <flux:callout variant="warning" icon="archive-box" :heading="__('This building is archived')" :text="__('It is kept with its history and cannot be changed. Its units do not count towards your bill.')" data-test="archived-banner" />
    @endif

    <div class="grid gap-6 lg:grid-cols-3">
        <section class="rounded-xl border border-zinc-200 bg-white p-5 lg:col-span-2 dark:border-zinc-700 dark:bg-zinc-900">
            <flux:heading size="lg" level="2">{{ __('Details') }}</flux:heading>

            <dl class="mt-4 grid gap-4 sm:grid-cols-2">
                <div>
                    <dt class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Type') }}</dt>
                    <dd class="font-medium text-zinc-900 dark:text-white">{{ __($property->property_type->label()) }}</dd>
                </div>
                <div>
                    <dt class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Owner') }}</dt>
                    <dd class="font-medium text-zinc-900 dark:text-white" data-test="building-owner">
                        @if ($owner === null)
                            {{ __('No owner on file') }}
                        @elseif ($canSeeOwner && ! $owner->trashed())
                            <a href="{{ route('workspace.owners.edit', [$organization, $owner]) }}" wire:navigate class="hover:underline">{{ $owner->display_name }}</a>
                        @else
                            {{ $owner->display_name }}@if ($owner->trashed()) <span class="text-sm font-normal text-zinc-500">({{ __('archived') }})</span>@endif
                        @endif
                    </dd>
                </div>
                <div class="sm:col-span-2">
                    <dt class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Address') }}</dt>
                    <dd class="text-zinc-900 dark:text-white">{{ collect([$property->address, $property->lga, $property->state])->filter()->join(', ') }}</dd>
                </div>
                @if ($property->landmark)
                    <div>
                        <dt class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Nearest landmark') }}</dt>
                        <dd class="text-zinc-900 dark:text-white">{{ $property->landmark }}</dd>
                    </div>
                @endif
                @if ($property->year_built)
                    <div>
                        <dt class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Year built') }}</dt>
                        <dd class="text-zinc-900 dark:text-white">{{ $property->year_built }}</dd>
                    </div>
                @endif
                @if ($property->description)
                    <div class="sm:col-span-2">
                        <dt class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Description') }}</dt>
                        <dd class="whitespace-pre-line text-zinc-900 dark:text-white">{{ $property->description }}</dd>
                    </div>
                @endif
            </dl>
        </section>

        <section class="rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900">
            <flux:heading size="lg" level="2">{{ __('Amenities') }}</flux:heading>
            @if (($property->amenities ?? []) === [])
                <flux:text class="mt-2">{{ __('None listed.') }}</flux:text>
            @else
                <ul class="mt-3 flex flex-wrap gap-2" data-test="building-amenities">
                    @foreach ($property->amenities as $amenity)
                        <li><flux:badge size="sm" color="zinc">{{ $amenity }}</flux:badge></li>
                    @endforeach
                </ul>
            @endif
        </section>
    </div>

    <section class="rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900" data-test="building-units">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <flux:heading size="lg" level="2">{{ __('Units') }}</flux:heading>
                <flux:text class="mt-1" data-test="occupancy">
                    @if ($unitCounts->total() === 0)
                        {{ __('No units yet.') }}
                    @else
                        {{ trans_choice(':occupied of :count unit occupied (:percent%)|:occupied of :count units occupied (:percent%)', $unitCounts->total(), ['occupied' => $unitCounts->occupied, 'count' => $unitCounts->total(), 'percent' => $unitCounts->occupancyPercent()]) }}
                    @endif
                </flux:text>
            </div>
            @if ($canAddUnits)
                <div class="flex flex-wrap gap-2">
                    <flux:button size="sm" icon="plus" :href="route('workspace.units.create', [$organization, $property])" wire:navigate data-test="add-unit">{{ __('Add unit') }}</flux:button>
                    <flux:button size="sm" variant="primary" icon="squares-plus" :href="route('workspace.units.bulk', [$organization, $property])" wire:navigate data-test="add-units-bulk">{{ __('Add several units') }}</flux:button>
                </div>
            @endif
        </div>

        @if ($unitCounts->total() > 0)
            <div class="mt-4 flex flex-wrap gap-2" role="group" aria-label="{{ __('Show units by status') }}" data-test="unit-counts">
                <flux:button size="sm" :variant="$boardFilter === null ? 'filled' : 'ghost'" wire:click="$set('boardStatus', '')" :aria-pressed="$boardFilter === null ? 'true' : 'false'" data-test="count-all">
                    {{ __('All') }} <span class="ms-1 font-semibold">{{ $unitCounts->total() }}</span>
                </flux:button>
                @foreach ($statuses as $statusOption)
                    <flux:button size="sm" :variant="$boardFilter === $statusOption ? 'filled' : 'ghost'" wire:click="$set('boardStatus', '{{ $statusOption->value }}')" :aria-pressed="$boardFilter === $statusOption ? 'true' : 'false'" data-test="count-{{ $statusOption->value }}">
                        {{ __($statusOption->label()) }} <span class="ms-1 font-semibold">{{ $unitCounts->byStatus()[$statusOption->value] }}</span>
                    </flux:button>
                @endforeach
            </div>

            @if ($boardUnits->isEmpty())
                <flux:text class="mt-4">{{ __('No unit is :status.', ['status' => mb_strtolower(__($boardFilter?->label() ?? ''))]) }}</flux:text>
            @else
                <ul class="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4" data-test="status-board">
                    @foreach ($boardUnits as $unit)
                        <li class="flex flex-col gap-2 rounded-lg border border-zinc-200 p-3 dark:border-zinc-700" wire:key="unit-{{ $unit->id }}" data-unit="{{ $unit->id }}" data-status="{{ $unit->status->value }}">
                            <div class="flex items-start justify-between gap-2">
                                <span class="font-medium text-zinc-900 dark:text-white">{{ $unit->unit_label }}</span>
                                <flux:badge size="sm" :color="$unit->status->color()">{{ __($unit->status->label()) }}</flux:badge>
                            </div>
                            <span class="text-sm text-zinc-500 dark:text-zinc-400">
                                {{ __($unit->unit_type->label()) }}@if ($unit->bedrooms !== null) · {{ trans_choice(':count bedroom|:count bedrooms', $unit->bedrooms, ['count' => $unit->bedrooms]) }}@endif
                            </span>
                            <span class="text-sm text-zinc-700 dark:text-zinc-300">{{ __(':amount a year', ['amount' => $unit->base_rent_kobo->format()]) }}</span>
                            @if ($canEditUnits || $canArchiveUnits)
                                <div class="flex flex-wrap gap-2">
                                    @if ($canEditUnits)
                                        <flux:button size="xs" :href="route('workspace.units.edit', [$organization, $property, $unit->id])" wire:navigate data-test="edit-unit-{{ $unit->id }}">{{ __('Edit') }}</flux:button>
                                    @endif
                                    @if ($canArchiveUnits && ! $unit->status->heldByLease())
                                        <flux:button size="xs" variant="ghost" wire:click="archiveUnit({{ $unit->id }})" wire:confirm="{{ __('Archive :label? It stops counting towards your bill, and you can restore it later.', ['label' => $unit->unit_label]) }}" data-test="archive-unit-{{ $unit->id }}">{{ __('Archive') }}</flux:button>
                                    @endif
                                </div>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @endif
        @elseif ($canAddUnits)
            <flux:text class="mt-2">{{ __('Add the flats, shops and offices in this building, one at a time or several at once.') }}</flux:text>
        @endif

        @if ($archivedUnitCount > 0 || $showArchivedUnits)
            <div class="mt-4">
                <flux:button size="sm" variant="ghost" wire:click="$toggle('showArchivedUnits')" data-test="toggle-archived-units">
                    {{ $showArchivedUnits ? __('Hide archived units') : trans_choice('Show :count archived unit|Show :count archived units', $archivedUnitCount, ['count' => $archivedUnitCount]) }}
                </flux:button>
                @if ($showArchivedUnits)
                    <ul class="mt-3 flex flex-col gap-2" data-test="archived-units">
                        @foreach ($archivedUnits as $unit)
                            <li class="flex items-center justify-between gap-2 rounded-lg border border-dashed border-zinc-200 p-3 dark:border-zinc-700" wire:key="archived-unit-{{ $unit->id }}">
                                <span class="text-zinc-700 dark:text-zinc-300">{{ $unit->unit_label }} <span class="text-sm text-zinc-500">({{ __('archived') }})</span></span>
                                @if ($canRestoreUnits)
                                    <flux:button size="xs" wire:click="restoreUnit({{ $unit->id }})" data-test="restore-unit-{{ $unit->id }}">{{ __('Restore') }}</flux:button>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
        @endif
    </section>

    <section class="rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900" data-test="building-photos">
        <div class="flex flex-col gap-1 sm:flex-row sm:items-baseline sm:justify-between">
            <flux:heading size="lg" level="2">{{ __('Photos') }}</flux:heading>
            <flux:text class="text-sm">{{ __(':count of :max', ['count' => count($photoList), 'max' => $maxPhotos]) }}</flux:text>
        </div>

        @if ($photoList === [])
            <flux:text class="mt-2">{{ __('No photos yet.') }}</flux:text>
        @else
            <ul class="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4">
                @foreach ($photoList as $photo)
                    <li class="flex flex-col gap-2" wire:key="photo-{{ $photo['id'] }}" data-photo="{{ $photo['id'] }}">
                        <a href="{{ $photo['large'] }}" target="_blank" rel="noopener noreferrer" class="block overflow-hidden rounded-lg">
                            <img src="{{ $photo['thumb'] }}" alt="{{ $photo['name'] }}" class="aspect-[4/3] w-full object-cover" loading="lazy" />
                        </a>
                        <div class="flex flex-wrap items-center gap-2">
                            @if ($photo['cover'])
                                <flux:badge size="sm" color="green">{{ __('Cover') }}</flux:badge>
                            @elseif ($canEdit)
                                <flux:button size="xs" wire:click="makeCover({{ $photo['id'] }})" data-test="cover-{{ $photo['id'] }}">{{ __('Make cover') }}</flux:button>
                            @endif
                            @if ($canRemovePhotos)
                                <flux:button size="xs" variant="ghost" wire:click="removePhoto({{ $photo['id'] }})" wire:confirm="{{ __('Remove this photo? It is deleted for good.') }}" data-test="remove-photo-{{ $photo['id'] }}">{{ __('Remove') }}</flux:button>
                            @endif
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif

        @if ($canAddPhotos)
            <form wire:submit="savePhotos" class="mt-5 flex flex-col gap-3" novalidate>
                <flux:input type="file" wire:model="photos" multiple accept="image/jpeg,image/png,image/webp" :label="__('Add photos')" :description="__('JPEG, PNG or WebP, up to 10 MB each, up to :max at a time.', ['max' => \Modules\Properties\Buildings\PropertyPhotos::MAX_PER_UPLOAD])" data-test="photo-input" />
                @error('photos')
                    <flux:text class="text-sm text-red-600 dark:text-red-400" role="alert">{{ $message }}</flux:text>
                @enderror
                @error('photos.*')
                    <flux:text class="text-sm text-red-600 dark:text-red-400" role="alert">{{ $message }}</flux:text>
                @enderror
                <div wire:loading wire:target="photos" class="text-sm text-zinc-500">{{ __('Uploading…') }}</div>
                <div class="flex gap-2">
                    <flux:button type="submit" variant="primary" icon="arrow-up-tray" wire:loading.attr="disabled" wire:target="photos,savePhotos" data-test="save-photos">{{ __('Save photos') }}</flux:button>
                    @if ($photos !== [])
                        <flux:button wire:click="clearPhotos">{{ __('Clear') }}</flux:button>
                    @endif
                </div>
            </form>
        @endif
    </section>
</x-page>
