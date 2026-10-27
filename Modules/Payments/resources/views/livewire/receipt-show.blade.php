{{--
    A receipt (Modules\Payments\Livewire\ReceiptShow). The void actions show
    only when the member may use them; the server checks each again. A
    receipt is never edited or deleted: a wrong one is voided, and its
    original PDF is kept beside the VOID copy.
--}}
<x-page :heading="__('Receipt :number', ['number' => $receipt->number])" :description="$receipt->amount_kobo->format().' · '.$row->tenantName">
    <x-slot:actions>
        <flux:button icon="arrow-left" :href="route('workspace.receipts.index', $organization)" wire:navigate>{{ __('All receipts') }}</flux:button>
        @if ($canSeeDocument)
            <flux:button icon="document-text" :href="route('workspace.receipts.document', [$organization, $receipt->id])" target="_blank" data-test="receipt-web">{{ __('View receipt') }}</flux:button>
            <flux:button icon="arrow-down-tray" :href="route('workspace.receipts.pdf', [$organization, $receipt->id])" data-test="receipt-pdf">{{ $receipt->isVoided() ? __('Original (PDF)') : __('Receipt (PDF)') }}</flux:button>
            @if ($receipt->isVoided())
                <flux:button icon="arrow-down-tray" :href="route('workspace.receipts.void-pdf', [$organization, $receipt->id])" data-test="receipt-void-pdf">{{ __('VOID copy (PDF)') }}</flux:button>
            @endif
        @endif
    </x-slot:actions>

    @if ($notice)
        <flux:callout variant="success" icon="check-circle" :heading="$notice" role="status" data-test="receipt-notice" />
    @endif

    @error('receipt')
        <flux:callout variant="warning" icon="exclamation-triangle" :heading="$message" role="alert" data-test="receipt-warning" />
    @enderror

    <div class="grid gap-6 lg:grid-cols-3">
        <section class="rounded-xl border border-zinc-200 bg-white p-5 lg:col-span-2 dark:border-zinc-700 dark:bg-zinc-900" data-test="receipt-details">
            <div class="flex flex-wrap items-center gap-2">
                <flux:heading size="lg" level="2">{{ __('Official receipt') }}</flux:heading>
                <flux:badge size="sm" :color="$receipt->status()->color()" data-test="receipt-status">{{ __($receipt->status()->label()) }}</flux:badge>
            </div>

            <dl class="mt-4 grid gap-4 sm:grid-cols-2">
                <div>
                    <dt class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Received from') }}</dt>
                    <dd class="font-medium text-zinc-900 dark:text-white">{{ $row->tenantName }}</dd>
                </div>
                <div>
                    <dt class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Amount') }}</dt>
                    <dd class="font-medium text-zinc-900 dark:text-white" data-test="receipt-amount">{{ $receipt->amount_kobo->format() }}</dd>
                </div>
                <div>
                    <dt class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Payment') }}</dt>
                    <dd class="text-zinc-900 dark:text-white">
                        @if ($canSeePayment)
                            <a href="{{ route('workspace.payments.show', [$organization, $receipt->payment_id]) }}" wire:navigate class="underline" data-test="receipt-payment">{{ __($printed->payment->method) }} · {{ __('paid :date', ['date' => $printed->payment->paidOn->format('d/m/Y')]) }}</a>
                        @else
                            {{ __($printed->payment->method) }} · {{ __('paid :date', ['date' => $printed->payment->paidOn->format('d/m/Y')]) }}
                        @endif
                    </dd>
                </div>
                <div>
                    <dt class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Issued') }}</dt>
                    <dd class="text-zinc-900 dark:text-white" data-test="receipt-issued-at">{{ $receipt->issued_at->timezone('Africa/Lagos')->format('d/m/Y H:i') }}</dd>
                </div>
                <div>
                    <dt class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Confirmed by') }}</dt>
                    <dd class="text-zinc-900 dark:text-white" data-test="receipt-confirmed-by">{{ $printed->confirmedBy }}</dd>
                </div>
                <div>
                    <dt class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Verification code') }}</dt>
                    <dd class="font-mono text-zinc-900 dark:text-white"><a href="{{ $verifyUrl }}" target="_blank" class="underline" data-test="receipt-verify">{{ $code }}</a></dd>
                </div>
                @if ($receipt->isVoided())
                    <div class="sm:col-span-2" data-test="receipt-voided">
                        <dt class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Voided') }}</dt>
                        <dd class="text-zinc-900 dark:text-white">
                            {{ $receipt->voided_at?->timezone('Africa/Lagos')->format('d/m/Y H:i') }} · {{ $receipt->void_reason }}
                            <span class="block text-sm text-zinc-500 dark:text-zinc-400">
                                @if ($receipt->void_single_approver)
                                    {{ __('Voided alone by :name, the organization\'s only owner or administrator.', ['name' => $row->approvedBy ?? __('a member')]) }}
                                @else
                                    {{ __('Asked by :requester, approved by :approver.', ['requester' => $row->requestedBy ?? __('a member'), 'approver' => $row->approvedBy ?? __('a member')]) }}
                                @endif
                            </span>
                        </dd>
                    </div>
                @endif
            </dl>
        </section>

        <section class="rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900" data-test="receipt-applied">
            <flux:heading size="lg" level="2">{{ __('Applied to') }}</flux:heading>
            <ul class="mt-4 flex flex-col gap-3">
                @forelse ($printed->allocations as $allocation)
                    <li class="flex justify-between gap-2" data-test="receipt-allocation">
                        <span class="text-zinc-900 dark:text-white">{{ $allocation->invoiceNumber }}</span>
                        <span class="text-zinc-900 dark:text-white">{{ $allocation->amount->format() }}</span>
                    </li>
                @empty
                    <li class="text-zinc-500 dark:text-zinc-400">{{ __('No bill: the whole amount went to the tenant\'s credit.') }}</li>
                @endforelse
            </ul>
            <flux:text class="mt-4 text-sm">{{ __('Outstanding after this payment: :amount. Credit: :credit.', ['amount' => $printed->outstandingAfter->format(), 'credit' => $printed->creditBalance->format()]) }}</flux:text>
        </section>

        @if ($receipt->voidPending())
            <section class="rounded-xl border border-amber-300 bg-amber-50 p-5 lg:col-span-3 dark:border-amber-700 dark:bg-amber-950" data-test="void-request">
                <flux:heading size="lg" level="2">{{ __('Void requested') }}</flux:heading>
                <flux:text class="mt-1">{{ __(':name asked to void this receipt on :date: :reason', ['name' => $row->requestedBy ?? __('A member'), 'date' => $receipt->void_requested_at?->timezone('Africa/Lagos')->format('d/m/Y H:i'), 'reason' => $receipt->void_request_reason]) }}</flux:text>
                @if ($ownRequest && ! $canVoidAlone)
                    <flux:text class="mt-1">{{ __('Another owner or administrator must approve it.') }}</flux:text>
                @endif
                <div class="mt-4 flex flex-wrap gap-2">
                    @if ($canApprove)
                        <flux:button variant="danger" wire:click="approveVoid" wire:confirm="{{ __('Void this receipt? Its verify page will say voided and the tenant is told.') }}" data-test="approve-void">{{ __('Approve and void') }}</flux:button>
                    @endif
                    @if ($canDismiss)
                        <flux:button wire:click="dismissVoid" data-test="dismiss-void">{{ $ownRequest ? __('Withdraw request') : __('Decline') }}</flux:button>
                    @endif
                </div>
            </section>
        @endif

        @if ($canVoidAlone)
            <section class="rounded-xl border border-zinc-200 bg-white p-5 lg:col-span-3 dark:border-zinc-700 dark:bg-zinc-900" data-test="void-alone">
                <flux:heading size="lg" level="2">{{ __('Void this receipt') }}</flux:heading>
                <flux:text class="mt-1">{{ __('You are the only owner or administrator, so you void it alone. The void is recorded as approved by a single person, with your reason. The original receipt is kept and a VOID copy is printed.') }}</flux:text>
                <form wire:submit="voidAlone" class="mt-4 flex flex-col gap-3 sm:max-w-xl" novalidate>
                    <flux:input wire:model="reason" :label="__('Reason')" maxlength="500" required data-test="void-alone-reason" />
                    <div>
                        <flux:button type="submit" variant="danger" wire:confirm="{{ __('Void this receipt? Its verify page will say voided and the tenant is told.') }}" data-test="void-alone-submit">{{ __('Void receipt') }}</flux:button>
                    </div>
                </form>
            </section>
        @elseif ($canRequest)
            <section class="rounded-xl border border-zinc-200 bg-white p-5 lg:col-span-3 dark:border-zinc-700 dark:bg-zinc-900" data-test="void-request-form">
                <flux:heading size="lg" level="2">{{ __('Ask to void this receipt') }}</flux:heading>
                <flux:text class="mt-1">{{ __('When it was issued in error. Another owner or administrator must approve; the original receipt is kept and a VOID copy is printed. The payment itself does not change.') }}</flux:text>
                <form wire:submit="requestVoid" class="mt-4 flex flex-col gap-3 sm:max-w-xl" novalidate>
                    <flux:input wire:model="reason" :label="__('Reason')" maxlength="500" required data-test="void-reason" />
                    <div>
                        <flux:button type="submit" data-test="request-void">{{ __('Ask to void') }}</flux:button>
                    </div>
                </form>
            </section>
        @endif
    </div>
</x-page>
