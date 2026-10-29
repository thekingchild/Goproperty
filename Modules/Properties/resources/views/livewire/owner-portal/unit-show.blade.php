{{--
    One of the owner's units with its lease history (Modules\Properties\Livewire\OwnerPortal\OwnerUnitShow,
    P03-16): every lease the agency activated on it, newest first, with the
    tenant and the moves as a timeline. Drafts never. Read-only.
--}}
@php
    /** @var \Modules\Properties\OwnerPortal\OwnerUnitPage $page */
    $unit = $page->unit;
@endphp
<x-page :heading="$unit->label.' · '.$page->building" :description="__('Lease history')">
    <x-slot:actions>
        <flux:button icon="arrow-left" :href="route('owner.buildings.show', $page->buildingId)" wire:navigate class="min-h-11">{{ $page->building }}</flux:button>
    </x-slot:actions>

    <section class="flex flex-col gap-4 rounded-2xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900" style="border-top: 6px solid {{ $page->agency->colour }}" data-test="owner-unit-page" data-unit="{{ $unit->id }}">
        @include('properties::owner-portal.partials.agency', ['agency' => $page->agency])

        <span class="flex flex-wrap items-center gap-2">
            <span class="text-zinc-600 dark:text-zinc-300">{{ __($unit->typeLabel) }}</span>
            <flux:badge size="sm" :color="$unit->statusColor">{{ __($unit->statusLabel) }}</flux:badge>
        </span>
    </section>

    <section class="flex flex-col gap-3" data-test="owner-unit-leases">
        <flux:heading size="lg" level="2">{{ __('Leases') }}</flux:heading>
        @forelse ($page->leases as $entry)
            @php($lease = $entry->history->lease)
            <div class="flex flex-col gap-2 rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900" data-test="owner-unit-lease" data-lease="{{ $lease->id }}">
                <span class="flex flex-wrap items-center justify-between gap-2">
                    <span class="font-medium break-all text-zinc-900 dark:text-white">{{ $lease->contractNumber ?? __('Lease') }}</span>
                    <flux:badge size="sm" :color="$entry->statusColor()">{{ __($lease->statusLabel) }}</flux:badge>
                </span>
                <span class="text-sm break-words text-zinc-600 dark:text-zinc-300">{{ $entry->tenantName }} · {{ $lease->startDate->format('d/m/Y') }} – {{ $lease->endDate->format('d/m/Y') }} · {{ __(':amount :cycle', ['amount' => $lease->rent->format(), 'cycle' => strtolower(__($lease->cycle->label()))]) }}</span>
                @include('properties::owner-portal.partials.timeline', ['events' => $entry->history->events])
                <span class="flex flex-wrap gap-x-4">
                    <a href="{{ route('owner.leases.show', $lease->id) }}" wire:navigate class="inline-flex min-h-11 items-center text-sm font-medium text-zinc-900 underline dark:text-white" data-test="owner-lease-link" data-lease="{{ $lease->id }}">{{ __('The lease') }}</a>
                    <a href="{{ route('owner.tenants.show', $lease->tenantProfileId) }}" wire:navigate class="inline-flex min-h-11 items-center text-sm font-medium text-zinc-900 underline dark:text-white" data-test="owner-tenant-link" data-tenant="{{ $lease->tenantProfileId }}">{{ __('The tenant') }}</a>
                </span>
            </div>
        @empty
            <flux:text>{{ __('No lease on this unit yet.') }}</flux:text>
        @endforelse
    </section>
</x-page>
