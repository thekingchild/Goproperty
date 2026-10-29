{{--
    A receipt for a payment on the owner's bills (Modules\Properties\Livewire\OwnerPortal\OwnerReceiptShow,
    P03-16) as an on-screen page built from what was kept at issue, never
    the issued PDF: number, date, amount, how the tenant paid, the owner's
    bills it settled and whether it was voided. No payment reference, no
    bank details. Read-only.
--}}
@php
    /** @var \Modules\Properties\OwnerPortal\OwnerReceiptPage $page */
    $receipt = $page->receipt;
@endphp
<x-page :heading="__('Receipt :number', ['number' => $receipt->number])" :description="$page->building">
    <x-slot:actions>
        <flux:button icon="arrow-left" :href="route('owner.buildings.show', $page->buildingId)" wire:navigate class="min-h-11">{{ $page->building }}</flux:button>
    </x-slot:actions>

    <section class="flex flex-col gap-4 rounded-2xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900" style="border-top: 6px solid {{ $page->agency->colour }}" data-test="owner-receipt-page" data-receipt="{{ $receipt->id }}">
        @include('properties::owner-portal.partials.agency', ['agency' => $page->agency])

        <div class="flex flex-wrap items-center gap-2">
            <flux:heading size="md" level="3">{{ __('Receipt') }}</flux:heading>
            <flux:badge size="sm" :color="$receipt->statusColor" data-test="owner-receipt-status">{{ __($receipt->statusLabel) }}</flux:badge>
        </div>

        <dl class="grid grid-cols-1 gap-3 sm:grid-cols-2">
            @if ($page->tenantName)
                <div>
                    <dt class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Tenant') }}</dt>
                    <dd class="font-medium break-words text-zinc-900 dark:text-white">{{ $page->tenantName }}</dd>
                </div>
            @endif
            <div>
                <dt class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Amount received') }}</dt>
                <dd class="font-semibold text-zinc-900 dark:text-white" data-test="owner-receipt-amount">{{ $receipt->amount->format() }}</dd>
            </div>
            <div>
                <dt class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Payment method') }}</dt>
                <dd class="text-zinc-900 dark:text-white" data-test="owner-receipt-method">{{ __($receipt->methodLabel) }}@if ($receipt->paidOn) · {{ $receipt->paidOn->format('d/m/Y') }}@endif</dd>
            </div>
            <div>
                <dt class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Issued') }}</dt>
                <dd class="text-zinc-900 dark:text-white">{{ $receipt->issuedAt->setTimezone('Africa/Lagos')->format('d/m/Y') }}</dd>
            </div>
            @if ($receipt->voidedAt)
                <div>
                    <dt class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Voided') }}</dt>
                    <dd class="text-zinc-900 dark:text-white" data-test="owner-receipt-voided">{{ $receipt->voidedAt->setTimezone('Africa/Lagos')->format('d/m/Y') }}</dd>
                </div>
            @endif
        </dl>

        <div class="flex flex-col gap-2">
            <flux:heading size="sm" level="4">{{ __('Bills it paid') }}</flux:heading>
            <ul class="flex flex-col divide-y divide-zinc-200 dark:divide-zinc-700" data-test="owner-receipt-lines">
                @foreach ($receipt->lines as $line)
                    <li class="flex flex-wrap items-center justify-between gap-2 py-1 text-sm" data-test="owner-receipt-line" data-bill="{{ $line->billId }}">
                        <a href="{{ route('owner.bills.show', $line->billId) }}" wire:navigate class="inline-flex min-h-11 min-w-0 flex-col justify-center break-words text-zinc-900 underline dark:text-white">
                            <span class="break-all">{{ $line->billNumber }}</span>
                            @if ($line->description !== '')<span class="text-zinc-500 no-underline dark:text-zinc-400">{{ $line->description }}</span>@endif
                        </a>
                        <span class="text-right">
                            <span class="font-medium text-zinc-900 dark:text-white">{{ $line->amount->format() }}</span>
                            @if ($line->settled)<br><span class="text-zinc-500 dark:text-zinc-400">{{ __('Paid in full') }}</span>@endif
                        </span>
                    </li>
                @endforeach
            </ul>
            @unless ($receipt->appliedHere()->equals($receipt->amount))
                <flux:text class="text-sm" data-test="owner-receipt-elsewhere">{{ __('The rest of this payment went to the tenant\'s other bills or to their credit.') }}</flux:text>
            @endunless
        </div>
    </section>
</x-page>
