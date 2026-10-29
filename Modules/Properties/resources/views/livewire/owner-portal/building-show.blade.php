{{--
    One of the owner's buildings (Modules\Properties\Livewire\OwnerPortal\OwnerBuildingShow,
    P03-15, P03-16): the agency, the units with their occupancy, active lease
    and a link to each unit's lease history, and the building's latest
    bills. Read-only.
--}}
@php
    /** @var \Modules\Properties\OwnerPortal\OwnerBuildingPage $page */
    $building = $page->building;
@endphp
<x-page :heading="$building->name" :description="$building->address">
    <x-slot:actions>
        <flux:button icon="arrow-left" :href="route('owner.home')" wire:navigate class="min-h-11">{{ __('My properties') }}</flux:button>
    </x-slot:actions>

    <section class="flex flex-col gap-4 rounded-2xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900" style="border-top: 6px solid {{ $page->agency->colour }}" data-test="owner-building-page" data-building="{{ $building->id }}">
        @include('properties::owner-portal.partials.agency', ['agency' => $page->agency])

        @if ($building->archived)
            <flux:callout variant="secondary" icon="archive-box" :text="__(':agency no longer manages this building. Its records are kept here for you to look at.', ['agency' => $page->agency->name])" />
        @endif

        <dl class="grid grid-cols-2 gap-3 sm:grid-cols-4" data-test="owner-building-occupancy">
            <div>
                <dt class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Units') }}</dt>
                <dd class="text-lg font-semibold text-zinc-900 dark:text-white">{{ $building->unitCount }}</dd>
            </div>
            <div>
                <dt class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Occupied') }}</dt>
                <dd class="text-lg font-semibold text-zinc-900 dark:text-white">{{ $building->occupied() }}</dd>
            </div>
            <div>
                <dt class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Vacant') }}</dt>
                <dd class="text-lg font-semibold text-zinc-900 dark:text-white">{{ $building->vacant() }}</dd>
            </div>
            <div>
                <dt class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Other') }}</dt>
                <dd class="text-lg font-semibold text-zinc-900 dark:text-white">{{ $building->other() }}</dd>
            </div>
        </dl>
    </section>

    <section class="flex flex-col gap-3" data-test="owner-units">
        <flux:heading size="lg" level="2">{{ __('Units') }}</flux:heading>
        @forelse ($page->units as $unit)
            <div class="flex flex-col gap-1 rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900" data-test="owner-unit" data-unit="{{ $unit->id }}">
                <span class="flex flex-wrap items-center justify-between gap-2">
                    <span class="font-medium break-words text-zinc-900 dark:text-white">{{ $unit->label }} <span class="text-sm font-normal text-zinc-500 dark:text-zinc-400">· {{ __($unit->typeLabel) }}</span></span>
                    <flux:badge size="sm" :color="$unit->statusColor">{{ __($unit->statusLabel) }}</flux:badge>
                </span>
                @if ($unit->lease)
                    <a href="{{ route('owner.leases.show', $unit->lease->lease->id) }}" wire:navigate class="inline-flex min-h-11 flex-col justify-center text-sm break-words text-zinc-600 underline-offset-2 hover:underline dark:text-zinc-300" data-test="owner-lease-link" data-lease="{{ $unit->lease->lease->id }}">
                        <span>{{ __('Let to :tenant', ['tenant' => $unit->lease->tenantName]) }}</span>
                        <span>{{ $unit->lease->lease->startDate->format('d/m/Y') }} – {{ $unit->lease->lease->endDate->format('d/m/Y') }} · {{ __(':amount :cycle', ['amount' => $unit->lease->lease->rent->format(), 'cycle' => strtolower($unit->lease->lease->cycle->label())]) }}</span>
                    </a>
                @endif
                <a href="{{ route('owner.units.show', $unit->id) }}" wire:navigate class="inline-flex min-h-11 items-center text-sm font-medium text-zinc-900 underline dark:text-white" data-test="owner-unit-history" data-unit="{{ $unit->id }}">{{ __('Lease history') }}</a>
            </div>
        @empty
            <flux:text>{{ __('No units yet.') }}</flux:text>
        @endforelse
    </section>

    <section class="flex flex-col gap-3" data-test="owner-building-bills">
        <flux:heading size="lg" level="2">{{ __('Bills') }}</flux:heading>
        @forelse ($page->bills as $bill)
            @include('properties::owner-portal.partials.bill-card', ['bill' => $bill])
        @empty
            <flux:text>{{ __('No bills yet.') }}</flux:text>
        @endforelse
        @if ($page->billCount > count($page->bills))
            <flux:text class="text-sm">{{ __('The latest :shown of :count bills.', ['shown' => count($page->bills), 'count' => $page->billCount]) }}</flux:text>
        @endif
    </section>
</x-page>
