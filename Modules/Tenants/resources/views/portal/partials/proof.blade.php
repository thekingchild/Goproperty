{{--
    One proof of payment in the tenant portal (P03-11b): what the tenant (or a
    member, for them) sent, and where it stands — waiting for the agency,
    accepted with its receipt, rejected with the agency's reason, archived.
    $proof: Payments' Contracts\TenantProof; $files: whether the file may be
    opened now (not while staff impersonate); $billNumber: the bill it was
    for, when shown outside the bill's page.
--}}
@php
    /** @var \Modules\Payments\Contracts\TenantProof $proof */
@endphp
<li class="flex flex-col gap-2 rounded-xl border border-zinc-200 p-4 dark:border-zinc-700" data-test="portal-proof" data-proof="{{ $proof->id }}">
    <span class="flex flex-wrap items-center justify-between gap-2">
        <span class="text-lg font-semibold text-zinc-900 dark:text-white">{{ $proof->amount->format() }}</span>
        <flux:badge size="sm" :color="$proof->statusColor" data-test="portal-proof-status">{{ __($proof->statusLabel) }}</flux:badge>
    </span>
    <span class="text-sm break-words text-zinc-600 dark:text-zinc-300">
        {{ __('Paid :date from :sender (:bank)', ['date' => $proof->paidOn->format('d/m/Y'), 'sender' => $proof->senderName, 'bank' => $proof->bankName]) }}
        @if ($billNumber)<br>{{ __('For bill :number', ['number' => $billNumber]) }}@endif
        <br>{{ $proof->byTenant ? __('Sent by you on :date', ['date' => $proof->submittedAt->timezone('Africa/Lagos')->format('d/m/Y')]) : __('Added by the agency on :date', ['date' => $proof->submittedAt->timezone('Africa/Lagos')->format('d/m/Y')]) }}
    </span>
    @if ($proof->rejectionReason)
        <flux:text class="text-sm break-words" data-test="portal-proof-reason">{{ __('Reason: :reason', ['reason' => $proof->rejectionReason]) }}</flux:text>
    @endif
    <span class="flex flex-wrap gap-2">
        @if ($proof->receiptId)
            <a href="{{ route('portal.receipts.show', $proof->receiptId) }}" wire:navigate class="inline-flex min-h-11 items-center gap-1 rounded-lg px-1 text-sm font-medium text-zinc-900 underline dark:text-white" data-test="portal-proof-receipt">{{ __('See the receipt') }}</a>
        @endif
        @if ($files && $proof->hasFile)
            <a href="{{ route('portal.proofs.file', $proof->id) }}" target="_blank" rel="noopener" class="inline-flex min-h-11 items-center gap-1 rounded-lg px-1 text-sm font-medium text-zinc-900 underline dark:text-white" data-test="portal-proof-file">{{ __('Open the file') }}</a>
        @endif
    </span>
</li>
