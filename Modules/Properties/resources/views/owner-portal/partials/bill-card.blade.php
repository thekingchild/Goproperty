{{--
    One bill in the owner portal's lists (P03-15): the number, what it is
    for and for whom (the tenant's name as issued), the status badge, the
    amount and what is still owed, the due date. The whole card is the link.
--}}
@php
    /** @var \Modules\Billing\Contracts\OwnerBill $bill */
@endphp
<a href="{{ route('owner.bills.show', $bill->id) }}" wire:navigate
    class="flex min-h-11 flex-col gap-2 rounded-xl border border-zinc-200 bg-white p-4 hover:border-zinc-300 dark:border-zinc-700 dark:bg-zinc-900"
    data-test="owner-bill" data-bill="{{ $bill->id }}">
    <span class="flex flex-wrap items-center justify-between gap-2">
        <span class="font-medium break-all text-zinc-900 dark:text-white">{{ $bill->number }}</span>
        <flux:badge size="sm" :color="$bill->statusColor">{{ __($bill->statusLabel) }}</flux:badge>
    </span>
    <span class="text-sm break-words text-zinc-600 dark:text-zinc-300">
        {{ __($bill->typeLabel) }}@if ($bill->unit) · {{ $bill->unit }}@endif @if ($bill->tenantName) · {{ $bill->tenantName }}@endif
        @if ($bill->periodStart && $bill->periodEnd)<br>{{ $bill->periodStart->format('d/m/Y') }} – {{ $bill->periodEnd->format('d/m/Y') }}@endif
    </span>
    <span class="flex flex-wrap items-end justify-between gap-2 text-sm text-zinc-600 dark:text-zinc-300">
        <span>{{ $bill->amount->format() }}@if (! $bill->isVoid() && $bill->outstanding->isPositive()) · {{ __(':amount still owed', ['amount' => $bill->outstanding->format()]) }}@endif</span>
        <span>{{ __('Due :date', ['date' => $bill->dueDate->format('d/m/Y')]) }}</span>
    </span>
</a>
