{{--
    One receipt in the tenant portal's lists (P03-11b): a stripe in the
    agency's colour as the receipt was issued, the number, the amount, the
    date and the status. The whole card is the link (a big target).
--}}
@php
    /** @var \Modules\Payments\Contracts\TenantReceipt $receipt */
    $printed = $receipt->document;
@endphp
<a href="{{ route('portal.receipts.show', $receipt->id) }}" wire:navigate
    class="block overflow-hidden rounded-xl border border-zinc-200 bg-white hover:border-zinc-300 dark:border-zinc-700 dark:bg-zinc-900"
    data-test="portal-receipt" data-receipt="{{ $receipt->id }}">
    <span class="block h-1.5" style="background-color: {{ $printed->brand->primary() }}" aria-hidden="true"></span>
    <span class="flex flex-col gap-2 p-4">
        <span class="flex flex-wrap items-center justify-between gap-2">
            <span class="font-medium break-all text-zinc-900 dark:text-white">{{ $printed->number }}</span>
            <flux:badge size="sm" :color="$receipt->voided ? 'zinc' : 'green'">{{ $receipt->voided ? __('Voided') : __('Valid') }}</flux:badge>
        </span>
        <span class="flex flex-wrap items-end justify-between gap-2">
            <span class="text-lg font-semibold text-zinc-900 dark:text-white">{{ $printed->amount->format() }}</span>
            <span class="text-sm text-zinc-600 dark:text-zinc-300">{{ __('Paid :date · :method', ['date' => $printed->payment->paidOn->format('d/m/Y'), 'method' => __($printed->payment->method)]) }}</span>
        </span>
    </span>
</a>
