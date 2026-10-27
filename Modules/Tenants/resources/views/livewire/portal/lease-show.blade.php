{{--
    One of my leases (Modules\Tenants\Livewire\Portal\PortalLeaseShow,
    P03-11a): the home, the agency, the lease's terms and its bills.
--}}
@php
    /** @var \Modules\Tenants\Portal\AgencyRentals $agency */
    /** @var \Modules\Tenants\Portal\PortalLease $home */
    $lease = $home->lease;
@endphp
<x-page :heading="$home->unit.' · '.$home->building" :description="$home->address">
    <x-slot:actions>
        <flux:button icon="arrow-left" :href="route('portal.home')" wire:navigate class="min-h-11">{{ __('My rentals') }}</flux:button>
    </x-slot:actions>

    @include('tenants::portal.partials.nav')

    <section class="flex flex-col gap-4 rounded-2xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900" style="border-top: 6px solid {{ $agency->colour }}" data-test="portal-lease-page" data-lease="{{ $lease->id }}">
        @include('tenants::portal.partials.agency', ['agency' => $agency, 'brand' => $agency->latestBill()?->brand])

        <div class="flex flex-wrap items-center gap-2">
            <flux:heading size="md" level="3">{{ __('Lease') }}</flux:heading>
            <flux:badge size="sm" :color="$home->statusColor()" data-test="portal-lease-status">{{ __($lease->statusLabel) }}</flux:badge>
        </div>

        <dl class="grid grid-cols-1 gap-3 sm:grid-cols-2">
            @if ($lease->contractNumber)
                <div>
                    <dt class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Contract number') }}</dt>
                    <dd class="font-medium break-all text-zinc-900 dark:text-white" data-test="portal-lease-contract">{{ $lease->contractNumber }}</dd>
                </div>
            @endif
            <div>
                <dt class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('From – to') }}</dt>
                <dd class="text-zinc-900 dark:text-white">{{ $lease->startDate->format('d/m/Y') }} – {{ $lease->endDate->format('d/m/Y') }}</dd>
            </div>
            <div>
                <dt class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Rent') }}</dt>
                <dd class="text-zinc-900 dark:text-white" data-test="portal-lease-rent">{{ $lease->rent->format() }}</dd>
            </div>
            <div>
                <dt class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Billed') }}</dt>
                <dd class="text-zinc-900 dark:text-white">{{ __($lease->cycle->label()) }}</dd>
            </div>
        </dl>
    </section>

    <section class="flex flex-col gap-3" data-test="portal-lease-bills">
        <flux:heading size="lg" level="2">{{ __('Bills for this lease') }}</flux:heading>
        @forelse ($agency->bills as $bill)
            @include('tenants::portal.partials.bill-card', ['bill' => $bill])
        @empty
            <flux:text>{{ __('No bills yet.') }}</flux:text>
        @endforelse
    </section>
</x-page>
