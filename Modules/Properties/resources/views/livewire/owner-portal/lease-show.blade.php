{{--
    A lease of one of the owner's units (Modules\Properties\Livewire\OwnerPortal\OwnerLeaseShow,
    P03-15): the unit, the tenant's name, the terms and the lease's bills. Read-only.
--}}
@php
    /** @var \Modules\Properties\OwnerPortal\OwnerLeasePage $page */
    $home = $page->lease;
    $lease = $home->lease;
@endphp
<x-page :heading="$home->unit.' · '.$home->building" :description="__('Lease')">
    <x-slot:actions>
        <flux:button icon="arrow-left" :href="route('owner.buildings.show', $lease->propertyId)" wire:navigate class="min-h-11">{{ $home->building }}</flux:button>
    </x-slot:actions>

    <section class="flex flex-col gap-4 rounded-2xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900" style="border-top: 6px solid {{ $page->agency->colour }}" data-test="owner-lease-page" data-lease="{{ $lease->id }}">
        @include('properties::owner-portal.partials.agency', ['agency' => $page->agency])

        <div class="flex flex-wrap items-center gap-2">
            <flux:heading size="md" level="3">{{ __('Lease') }}</flux:heading>
            <flux:badge size="sm" :color="$home->statusColor()" data-test="owner-lease-status">{{ __($lease->statusLabel) }}</flux:badge>
        </div>

        <dl class="grid grid-cols-1 gap-3 sm:grid-cols-2">
            <div>
                <dt class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Tenant') }}</dt>
                <dd class="font-medium break-words text-zinc-900 dark:text-white" data-test="owner-lease-tenant">{{ $home->tenantName }}</dd>
            </div>
            @if ($home->tenantPhone || $home->tenantEmail)
                {{-- Only when the agency shares tenants' contact details with this owner (P08-05). --}}
                <div>
                    <dt class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Tenant\'s contact details') }}</dt>
                    <dd class="break-words text-zinc-900 dark:text-white" data-test="owner-lease-tenant-contacts">{{ collect([$home->tenantPhone, $home->tenantEmail])->filter()->implode(' · ') }}</dd>
                </div>
            @endif
            @if ($lease->contractNumber)
                <div>
                    <dt class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Contract number') }}</dt>
                    <dd class="break-all text-zinc-900 dark:text-white">{{ $lease->contractNumber }}</dd>
                </div>
            @endif
            <div>
                <dt class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('From – to') }}</dt>
                <dd class="text-zinc-900 dark:text-white">{{ $lease->startDate->format('d/m/Y') }} – {{ $lease->endDate->format('d/m/Y') }}</dd>
            </div>
            <div>
                <dt class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Rent') }}</dt>
                <dd class="text-zinc-900 dark:text-white" data-test="owner-lease-rent">{{ $lease->rent->format() }} · {{ __($lease->cycle->label()) }}</dd>
            </div>
        </dl>
    </section>

    <section class="flex flex-col gap-3" data-test="owner-lease-bills">
        <flux:heading size="lg" level="2">{{ __('Bills for this lease') }}</flux:heading>
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
