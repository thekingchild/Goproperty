{{--
    What was paid on the owner's bills (P03-16), newest first: each
    confirmed payment's part of a bill with its receipt (an on-screen page),
    or tenant credit used. Never a payment reference or bank details; the
    proofs tenants upload are not shown.
    $payments: list<Modules\Payments\Contracts\OwnerPayment>; $billNumbers: array<int, string> (optional).
--}}
@forelse ($payments as $payment)
    <div class="flex flex-col gap-1 rounded-xl border border-zinc-200 bg-white p-4 text-sm dark:border-zinc-700 dark:bg-zinc-900" data-test="owner-payment" data-bill="{{ $payment->billId }}" data-kind="{{ $payment->kind }}">
        <span class="flex flex-wrap items-center justify-between gap-2">
            <span class="font-medium text-zinc-900 dark:text-white">{{ $payment->amount->format() }}</span>
            <span class="flex flex-wrap gap-1">
                @if ($payment->receiptVoided)
                    <flux:badge size="sm" color="zinc">{{ __('Receipt voided') }}</flux:badge>
                @endif
                @if ($payment->refunded)
                    <flux:badge size="sm" color="zinc">{{ __('Refunded') }}</flux:badge>
                @endif
            </span>
        </span>
        <span class="break-words text-zinc-600 dark:text-zinc-300">
            {{ __($payment->methodLabel) }} · {{ ($payment->paidOn ?? $payment->at->setTimezone('Africa/Lagos'))->format('d/m/Y') }}@if (isset($billNumbers[$payment->billId])) · {{ __('Bill :number', ['number' => $billNumbers[$payment->billId]]) }}@endif
        </span>
        @if ($payment->receiptId && $payment->receiptNumber)
            <a href="{{ route('owner.receipts.show', $payment->receiptId) }}" wire:navigate class="inline-flex min-h-11 items-center font-medium break-all text-zinc-900 underline dark:text-white" data-test="owner-receipt-link" data-receipt="{{ $payment->receiptId }}">{{ __('Receipt :number', ['number' => $payment->receiptNumber]) }}</a>
        @endif
    </div>
@empty
    <flux:text>{{ __('Nothing paid yet.') }}</flux:text>
@endforelse
