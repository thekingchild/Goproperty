{{--
    One bill in the tenant portal's lists (P03-11a): a stripe in the agency's
    colour as the bill was issued, the number, what it is for, the status
    badge, what is still owed (or the amount) and the due date. The whole
    card is the link (a big target).
--}}
@php
    /** @var \Modules\Billing\Contracts\TenantBill $bill */
@endphp
<a href="{{ route('portal.bills.show', $bill->id) }}" wire:navigate
    class="block overflow-hidden rounded-xl border border-zinc-200 bg-white hover:border-zinc-300 dark:border-zinc-700 dark:bg-zinc-900"
    data-test="portal-bill" data-bill="{{ $bill->id }}">
    <span class="block h-1.5" style="background-color: {{ $bill->brand->primary() }}" aria-hidden="true"></span>
    <span class="flex flex-col gap-2 p-4">
        <span class="flex flex-wrap items-center justify-between gap-2">
            <span class="font-medium break-all text-zinc-900 dark:text-white">{{ $bill->number }}</span>
            <flux:badge size="sm" :color="$bill->statusColor">{{ __($bill->statusLabel) }}</flux:badge>
        </span>
        <span class="text-sm break-words text-zinc-600 dark:text-zinc-300">
            {{ __($bill->typeLabel) }}@if ($bill->periodStart && $bill->periodEnd) · {{ $bill->periodStart->format('d/m/Y') }} – {{ $bill->periodEnd->format('d/m/Y') }}@endif
            @if ($bill->property)<br>{{ $bill->property }}@endif
        </span>
        <span class="flex flex-wrap items-end justify-between gap-2">
            @if ($bill->asksForMoney())
                <span>
                    <span class="block text-xs text-zinc-500 dark:text-zinc-400">{{ __('To pay') }}</span>
                    <span class="text-lg font-semibold text-zinc-900 dark:text-white" data-test="portal-bill-due">{{ $bill->outstanding->format() }}</span>
                </span>
                <span class="text-sm text-zinc-600 dark:text-zinc-300">{{ __('Due :date', ['date' => $bill->dueDate->format('d/m/Y')]) }}</span>
            @else
                <span class="text-sm text-zinc-600 dark:text-zinc-300">{{ $bill->amount->format() }}</span>
                <span class="text-sm text-zinc-500 dark:text-zinc-400">{{ $bill->isVoid() ? __('Cancelled: do not pay') : __('Paid') }}</span>
            @endif
        </span>
    </span>
</a>
