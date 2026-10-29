{{--
    A lease of one of the owner's units (Modules\Properties\Livewire\OwnerPortal\OwnerLeaseShow,
    P03-15, P03-16): the unit, the tenant (name, phone, email; the rest on
    the tenant's page), the terms, the issued contract, the lease's moves,
    the deposit ledger, what was paid and the bills. Read-only: no payment
    reference or bank details anywhere.
--}}
@php
    /** @var \Modules\Properties\OwnerPortal\OwnerLeasePage $page */
    $home = $page->lease;
    $lease = $home->lease;
    $history = $page->history;
@endphp
<x-page :heading="$home->unit.' · '.$home->building" :description="__('Lease')">
    <x-slot:actions>
        <flux:button icon="arrow-left" :href="route('owner.buildings.show', $lease->propertyId)" wire:navigate class="min-h-11">{{ $home->building }}</flux:button>
        <flux:button icon="clock" :href="route('owner.units.show', $lease->unitId)" wire:navigate class="min-h-11" data-test="owner-lease-unit-history">{{ __('Lease history') }}</flux:button>
        @if ($history->hasContract)
            <flux:button icon="arrow-down-tray" :href="route('owner.leases.contract', $lease->id)" class="min-h-11" data-test="owner-lease-contract">{{ __('Contract (PDF)') }}</flux:button>
        @endif
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
                @if ($home->tenantPhone || $home->tenantEmail)
                    <dd class="text-sm break-words text-zinc-600 dark:text-zinc-300" data-test="owner-lease-tenant-contacts">{{ collect([$home->tenantPhone, $home->tenantEmail])->filter()->implode(' · ') }}</dd>
                @endif
                <dd><a href="{{ route('owner.tenants.show', $lease->tenantProfileId) }}" wire:navigate class="inline-flex min-h-11 items-center text-sm font-medium text-zinc-900 underline dark:text-white" data-test="owner-tenant-link" data-tenant="{{ $lease->tenantProfileId }}">{{ __('All the tenant\'s details') }}</a></dd>
            </div>
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

        @if ($history->events !== [])
            <div class="flex flex-col gap-2">
                <flux:heading size="sm" level="4">{{ __('History') }}</flux:heading>
                @include('properties::owner-portal.partials.timeline', ['events' => $history->events])
            </div>
        @endif
    </section>

    <section class="flex flex-col gap-2 rounded-2xl border border-zinc-200 bg-white p-4 text-sm dark:border-zinc-700 dark:bg-zinc-900" data-test="owner-lease-deposit">
        <flux:heading size="lg" level="2">{{ __('Deposit') }}</flux:heading>
        @if ($history->depositLines === [])
            <flux:text>{{ $history->deposit->isPositive() ? __('Agreed: :amount. Nothing recorded yet.', ['amount' => $history->deposit->format()]) : __('No deposit on this lease.') }}</flux:text>
        @else
            <ul class="flex flex-col divide-y divide-zinc-200 dark:divide-zinc-700">
                @foreach ($history->depositLines as $line)
                    <li class="flex flex-wrap items-start justify-between gap-2 py-2" data-test="owner-deposit-line" data-type="{{ $line->type }}">
                        <span class="min-w-0 break-words text-zinc-700 dark:text-zinc-200">{{ __($line->label) }} · {{ $line->recordedAt->setTimezone('Africa/Lagos')->format('d/m/Y') }}@if ($line->note)<br><span class="text-zinc-500 dark:text-zinc-400">{{ $line->note }}</span>@endif</span>
                        <span class="font-medium text-zinc-900 dark:text-white">{{ $line->amount->format() }}</span>
                    </li>
                @endforeach
            </ul>
            <p class="flex flex-wrap justify-between gap-2 font-semibold text-zinc-900 dark:text-white" data-test="owner-deposit-balance"><span>{{ __('Held now') }}</span><span>{{ $history->depositBalance->format() }}</span></p>
        @endif
    </section>

    <section class="flex flex-col gap-3" data-test="owner-lease-payments">
        <flux:heading size="lg" level="2">{{ __('Payments') }}</flux:heading>
        @include('properties::owner-portal.partials.payments', ['payments' => $page->payments, 'billNumbers' => $page->billNumbers])
        @if ($page->paymentCount > count($page->payments))
            <flux:text class="text-sm">{{ __('The latest :shown of :count payments.', ['shown' => count($page->payments), 'count' => $page->paymentCount]) }}</flux:text>
        @endif
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
