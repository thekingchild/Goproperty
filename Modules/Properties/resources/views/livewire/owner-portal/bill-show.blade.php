{{--
    A bill of one of the owner's buildings (Modules\Properties\Livewire\OwnerPortal\OwnerBillShow,
    P03-15): status, amounts, period, lines and the tenant's name as issued.
    No pay instructions and no invoice: owners follow, the agency collects.
    Read-only.
--}}
@php
    /** @var \Modules\Properties\OwnerPortal\OwnerBillPage $page */
    $bill = $page->bill;
@endphp
<x-page :heading="__('Bill :number', ['number' => $bill->number])" :description="$page->building.($bill->unit ? ' · '.$bill->unit : '')">
    <x-slot:actions>
        <flux:button icon="arrow-left" :href="route('owner.buildings.show', $page->buildingId)" wire:navigate class="min-h-11">{{ $page->building }}</flux:button>
        @if ($page->leaseId)
            <flux:button icon="document-text" :href="route('owner.leases.show', $page->leaseId)" wire:navigate class="min-h-11" data-test="owner-bill-lease">{{ __('Lease') }}</flux:button>
        @endif
    </x-slot:actions>

    <section class="flex flex-col gap-4 rounded-2xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900" style="border-top: 6px solid {{ $page->agency->colour }}" data-test="owner-bill-page" data-bill="{{ $bill->id }}">
        @include('properties::owner-portal.partials.agency', ['agency' => $page->agency])

        <div class="flex flex-wrap items-center gap-2">
            <flux:heading size="md" level="3">{{ __($bill->typeLabel) }}</flux:heading>
            <flux:badge size="sm" :color="$bill->statusColor" data-test="owner-bill-status">{{ __($bill->statusLabel) }}</flux:badge>
        </div>

        <dl class="grid grid-cols-1 gap-3 sm:grid-cols-2">
            @if ($bill->tenantName)
                <div>
                    <dt class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Tenant') }}</dt>
                    <dd class="font-medium break-words text-zinc-900 dark:text-white" data-test="owner-bill-tenant">{{ $bill->tenantName }}</dd>
                </div>
            @endif
            @if ($bill->periodStart && $bill->periodEnd)
                <div>
                    <dt class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Period') }}</dt>
                    <dd class="text-zinc-900 dark:text-white">{{ $bill->periodStart->format('d/m/Y') }} – {{ $bill->periodEnd->format('d/m/Y') }}</dd>
                </div>
            @endif
            <div>
                <dt class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Issued') }}</dt>
                <dd class="text-zinc-900 dark:text-white">{{ $bill->issuedAt->timezone('Africa/Lagos')->format('d/m/Y') }}</dd>
            </div>
            <div>
                <dt class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Due') }}</dt>
                <dd class="text-zinc-900 dark:text-white">{{ $bill->dueDate->format('d/m/Y') }}</dd>
            </div>
            <div>
                <dt class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Amount') }}</dt>
                <dd class="text-zinc-900 dark:text-white" data-test="owner-bill-amount">{{ $bill->amount->format() }}</dd>
            </div>
            <div>
                <dt class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Paid') }}</dt>
                <dd class="text-zinc-900 dark:text-white">{{ $bill->paid->format() }}</dd>
            </div>
            @unless ($bill->isVoid())
                <div>
                    <dt class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Still owed') }}</dt>
                    <dd class="font-semibold text-zinc-900 dark:text-white" data-test="owner-bill-outstanding">{{ $bill->outstanding->format() }}</dd>
                </div>
            @endunless
        </dl>

        @if ($bill->lines !== [])
            <ul class="flex flex-col divide-y divide-zinc-200 dark:divide-zinc-700" data-test="owner-bill-lines">
                @foreach ($bill->lines as $line)
                    <li class="flex flex-wrap items-start justify-between gap-2 py-2 text-sm">
                        <span class="min-w-0 break-words text-zinc-700 dark:text-zinc-200">{{ $line['label'] }}@if ($line['period'])<br><span class="text-zinc-500 dark:text-zinc-400">{{ $line['period'] }}</span>@endif</span>
                        <span class="font-medium text-zinc-900 dark:text-white">{{ $line['amount'] }}</span>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>
</x-page>
