{{--
    A payment (Modules\Payments\Livewire\PaymentShow). Confirm and Reject
    show only while the payment waits and the member may; the server checks
    both again. A confirmed payment has no edit or delete. A payment from a
    proof of payment (P03-09) shows the proof for review: its file behind
    PaymentProofPolicy::viewFile, and a warning when the same file came with
    another proof; a proof is archived, never deleted.
--}}
<x-page :heading="$payment->amount_kobo->format().' · '.$row->tenantName" :description="__($payment->method->label()).' · '.__('paid :date', ['date' => $payment->paid_at->format('d/m/Y')])">
    <x-slot:actions>
        <flux:button icon="arrow-left" :href="route('workspace.payments.index', $organization)" wire:navigate>{{ __('All payments') }}</flux:button>
    </x-slot:actions>

    @if (session('payment-status'))
        <flux:callout variant="success" icon="check-circle" :heading="session('payment-status')" role="status" data-test="payment-flash" />
    @endif

    @if ($notice)
        <flux:callout variant="success" icon="check-circle" :heading="$notice" role="status" data-test="payment-notice" />
    @endif

    @error('payment')
        <flux:callout variant="warning" icon="exclamation-triangle" :heading="$message" role="alert" data-test="payment-warning" />
    @enderror

    <div class="grid gap-6 lg:grid-cols-3">
        <section class="rounded-xl border border-zinc-200 bg-white p-5 lg:col-span-2 dark:border-zinc-700 dark:bg-zinc-900" data-test="payment-details">
            <div class="flex flex-wrap items-center gap-2">
                <flux:heading size="lg" level="2">{{ __('Payment') }}</flux:heading>
                <flux:badge size="sm" :color="$payment->status->color()" data-test="payment-status">{{ __($payment->status->label()) }}</flux:badge>
            </div>

            <dl class="mt-4 grid gap-4 sm:grid-cols-2">
                <div>
                    <dt class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Tenant') }}</dt>
                    <dd class="font-medium text-zinc-900 dark:text-white"><a href="{{ route('workspace.tenants.show', [$organization, $payment->tenant_profile_id]) }}" wire:navigate class="hover:underline">{{ $row->tenantName }}</a></dd>
                </div>
                <div>
                    <dt class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Amount') }}</dt>
                    <dd class="font-medium text-zinc-900 dark:text-white" data-test="payment-amount">{{ $payment->amount_kobo->format() }}</dd>
                </div>
                <div>
                    <dt class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Method') }}</dt>
                    <dd class="text-zinc-900 dark:text-white">{{ __($payment->method->label()) }} · {{ __($payment->source->label()) }}</dd>
                </div>
                <div>
                    <dt class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Paid on') }}</dt>
                    <dd class="text-zinc-900 dark:text-white" data-test="payment-paid-at">{{ $payment->paid_at->format('d/m/Y') }}</dd>
                </div>
                @if ($payment->payer_name)
                    <div>
                        <dt class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Paid by') }}</dt>
                        <dd class="text-zinc-900 dark:text-white">{{ $payment->payer_name }} · {{ __($payment->payer_type->label()) }}</dd>
                    </div>
                @endif
                @if ($payment->reference)
                    <div>
                        <dt class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Reference') }}</dt>
                        <dd class="font-mono text-zinc-900 dark:text-white">{{ $payment->reference }}</dd>
                    </div>
                @endif
                @if ($bill)
                    <div>
                        <dt class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('For bill') }}</dt>
                        <dd class="text-zinc-900 dark:text-white" data-test="payment-bill">
                            @if ($billLink($bill))
                                <a href="{{ route('workspace.bills.show', [$organization, $bill->id]) }}" wire:navigate class="underline">{{ $bill->number }}</a>
                            @else
                                {{ $bill->number }}
                            @endif
                        </dd>
                    </div>
                @endif
                @if ($payment->note)
                    <div class="sm:col-span-2">
                        <dt class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Note') }}</dt>
                        <dd class="text-zinc-900 dark:text-white">{{ $payment->note }}</dd>
                    </div>
                @endif
                <div>
                    <dt class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Recorded') }}</dt>
                    <dd class="text-zinc-900 dark:text-white">{{ $payment->created_at?->timezone('Africa/Lagos')->format('d/m/Y H:i') }}</dd>
                </div>
                @if ($payment->confirmed_at)
                    <div>
                        <dt class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Confirmed') }}</dt>
                        <dd class="text-zinc-900 dark:text-white" data-test="payment-confirmed-at">{{ $payment->confirmed_at->timezone('Africa/Lagos')->format('d/m/Y H:i') }}@if ($payment->confirmation_note) · {{ $payment->confirmation_note }}@endif</dd>
                    </div>
                @endif
                @if ($receipt)
                    <div>
                        <dt class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Receipt') }}</dt>
                        <dd class="text-zinc-900 dark:text-white" data-test="payment-receipt">
                            <a href="{{ route('workspace.receipts.show', [$organization, $receipt->id]) }}" wire:navigate class="underline">{{ $receipt->number }}</a>
                            @if ($receipt->isVoided())
                                · {{ __('Voided') }}
                            @endif
                        </dd>
                    </div>
                @endif
                @if ($payment->rejected_at)
                    <div class="sm:col-span-2">
                        <dt class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Rejected') }}</dt>
                        <dd class="text-zinc-900 dark:text-white" data-test="payment-rejection">{{ $payment->rejected_at->timezone('Africa/Lagos')->format('d/m/Y H:i') }} · {{ $payment->rejection_reason }}</dd>
                    </div>
                @endif
            </dl>
        </section>

        <section class="rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900" data-test="payment-split">
            <flux:heading size="lg" level="2">{{ $plan ? __('Confirming settles') : __('Settled') }}</flux:heading>

            @foreach ($partPaymentNotes as $partPaymentNote)
                <flux:callout variant="warning" icon="exclamation-triangle" class="mt-3" :heading="$partPaymentNote" data-test="part-payment-flag" />
            @endforeach

            <ul class="mt-4 flex flex-col gap-3">
                @if ($plan)
                    @foreach ($plan->allocations as $billId => $amount)
                        <li class="flex justify-between gap-2" data-test="planned-bill">
                            <span class="text-zinc-900 dark:text-white">{{ $plan->bills[$billId]->number }}</span>
                            <span class="text-zinc-900 dark:text-white">{{ $amount->format() }}</span>
                        </li>
                    @endforeach
                    @if ($plan->credit->isPositive())
                        <li class="flex justify-between gap-2" data-test="planned-credit">
                            <span class="text-zinc-500 dark:text-zinc-400">{{ __('To the tenant\'s credit') }}</span>
                            <span class="text-zinc-900 dark:text-white">{{ $plan->credit->format() }}</span>
                        </li>
                    @endif
                @else
                    @foreach ($allocations as $allocation)
                        <li class="flex justify-between gap-2" data-test="allocation">
                            <span class="text-zinc-900 dark:text-white">
                                @if ($billLink($allocation['bill']))
                                    <a href="{{ route('workspace.bills.show', [$organization, $allocation['billId']]) }}" wire:navigate class="underline">{{ $allocation['bill']->number }}</a>
                                @else
                                    {{ $allocation['bill']?->number ?? __('A bill in another building') }}
                                @endif
                            </span>
                            <span class="text-zinc-900 dark:text-white">{{ $allocation['amount']->format() }}</span>
                        </li>
                    @endforeach
                    @if ($credited->isPositive())
                        <li class="flex justify-between gap-2" data-test="credited">
                            <span class="text-zinc-500 dark:text-zinc-400">{{ __('To the tenant\'s credit') }}</span>
                            <span class="text-zinc-900 dark:text-white">{{ $credited->format() }}</span>
                        </li>
                    @endif
                    @if ($allocations === [] && ! $credited->isPositive())
                        <li class="text-zinc-500 dark:text-zinc-400">{{ __('Nothing.') }}</li>
                    @endif
                @endif
            </ul>

            <flux:text class="mt-4 text-sm" data-test="tenant-credit">{{ __('Tenant\'s credit now: :amount. Credit is used for the next bill issued to them.', ['amount' => $tenantCredit->format()]) }}</flux:text>
        </section>

        @if ($proof)
            <section class="rounded-xl border border-zinc-200 bg-white p-5 lg:col-span-3 dark:border-zinc-700 dark:bg-zinc-900" data-test="payment-proof">
                <div class="flex flex-wrap items-center gap-2">
                    <flux:heading size="lg" level="2">{{ __('Proof of payment') }}</flux:heading>
                    <flux:badge size="sm" :color="$proofStatus->color()" data-test="proof-status">{{ __($proofStatus->label()) }}</flux:badge>
                    @if ($sameFile !== [])
                        <flux:badge size="sm" color="red" icon="exclamation-triangle" data-test="proof-duplicate-badge">{{ __('Same file as another proof') }}</flux:badge>
                    @endif
                </div>

                @if ($sameFile !== [])
                    <flux:callout class="mt-4" variant="warning" icon="exclamation-triangle" :heading="__('This file was also uploaded with another proof. Check that it is not the same transfer sent twice before confirming.')" role="alert" data-test="proof-duplicate">
                        <flux:callout.text>
                            <ul class="mt-1 flex flex-col gap-1">
                                @foreach ($sameFile as $other)
                                    <li data-test="same-file-proof">
                                        @if ($other['link'] && $other['payment'])
                                            <a href="{{ route('workspace.payments.show', [$organization, $other['payment']->id]) }}" wire:navigate class="underline">{{ $other['tenant'] }} · {{ $other['proof']->amount_kobo->format() }} · {{ __('paid :date', ['date' => $other['proof']->paid_on->format('d/m/Y')]) }}</a>
                                        @else
                                            {{ __('A proof of a tenant you cannot open') }}
                                        @endif
                                        · {{ __($other['proof']->status($other['payment'])->label()) }}
                                        · {{ __('uploaded :date', ['date' => $other['proof']->submitted_at->timezone('Africa/Lagos')->format('d/m/Y H:i')]) }}
                                    </li>
                                @endforeach
                            </ul>
                        </flux:callout.text>
                    </flux:callout>
                @endif

                <div class="mt-4 grid gap-6 sm:grid-cols-3">
                    <dl class="grid gap-4 sm:col-span-2 sm:grid-cols-2">
                        <div>
                            <dt class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Amount on the proof') }}</dt>
                            <dd class="font-medium text-zinc-900 dark:text-white" data-test="proof-amount">{{ $proof->amount_kobo->format() }}</dd>
                        </div>
                        <div>
                            <dt class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Date paid') }}</dt>
                            <dd class="text-zinc-900 dark:text-white">{{ $proof->paid_on->format('d/m/Y') }}</dd>
                        </div>
                        <div>
                            <dt class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Sender') }}</dt>
                            <dd class="text-zinc-900 dark:text-white" data-test="proof-sender">{{ $proof->sender_name }}</dd>
                        </div>
                        <div>
                            <dt class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Bank') }}</dt>
                            <dd class="text-zinc-900 dark:text-white" data-test="proof-bank">{{ $proof->bank_name }}</dd>
                        </div>
                        @if ($proof->reference)
                            <div>
                                <dt class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Narration or reference') }}</dt>
                                <dd class="font-mono text-zinc-900 dark:text-white">{{ $proof->reference }}</dd>
                            </div>
                        @endif
                        @if ($proof->note)
                            <div>
                                <dt class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Note') }}</dt>
                                <dd class="text-zinc-900 dark:text-white">{{ $proof->note }}</dd>
                            </div>
                        @endif
                        <div class="sm:col-span-2">
                            <dt class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Sent by') }}</dt>
                            <dd class="text-zinc-900 dark:text-white" data-test="proof-submitter">{{ $proofSender ?? __('Unknown') }} · {{ __($proof->submitted_by->label()) }} · {{ $proof->submitted_at->timezone('Africa/Lagos')->format('d/m/Y H:i') }}</dd>
                        </div>
                        @if ($proof->archived_at)
                            <div class="sm:col-span-2">
                                <dt class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Archived') }}</dt>
                                <dd class="text-zinc-900 dark:text-white" data-test="proof-archived">{{ $proof->archived_at->timezone('Africa/Lagos')->format('d/m/Y H:i') }} · {{ $proofArchiver ?? __('Unknown') }} · {{ $proof->archive_reason }}</dd>
                            </div>
                        @endif
                    </dl>

                    <div class="flex flex-col gap-2">
                        @if ($canViewProofFile)
                            @if ($proofIsImage)
                                <a href="{{ route('workspace.payments.proofs.file', [$organization, $proof->id]) }}" target="_blank" rel="noopener" data-test="proof-file-link">
                                    <img src="{{ route('workspace.payments.proofs.thumb', [$organization, $proof->id]) }}" alt="{{ __('Proof of payment') }}" class="max-h-64 rounded-lg border border-zinc-200 object-contain dark:border-zinc-700" loading="lazy" data-test="proof-thumb" />
                                </a>
                            @endif
                            <flux:button size="sm" icon="arrow-top-right-on-square" :href="route('workspace.payments.proofs.file', [$organization, $proof->id])" target="_blank" data-test="proof-open">{{ $proofIsImage ? __('Open the image') : __('Open the PDF') }}</flux:button>
                        @else
                            <flux:text class="text-sm" data-test="proof-file-hidden">{{ __('The file is not shown in this session.') }}</flux:text>
                        @endif
                    </div>
                </div>

                @if ($canArchiveProof)
                    <form wire:submit="archiveProof" class="mt-4 flex flex-col gap-3 sm:max-w-xl" novalidate data-test="proof-archive">
                        <flux:input wire:model="archiveReason" :label="__('Remove this proof')" :description="__('A proof is kept as evidence: removing archives it with the reason, and the file is kept. Reject the payment too if it should not count.')" maxlength="500" data-test="proof-archive-reason" />
                        <div>
                            <flux:button type="submit" size="sm" wire:confirm="{{ __('Archive this proof? It is kept with its file and the reason.') }}" data-test="archive-proof">{{ __('Archive proof') }}</flux:button>
                        </div>
                    </form>
                @endif
            </section>
        @endif

        @if ($canConfirm)
            <section class="rounded-xl border border-zinc-200 bg-white p-5 lg:col-span-3 dark:border-zinc-700 dark:bg-zinc-900" data-test="payment-confirm">
                <flux:heading size="lg" level="2">{{ __('Confirm this payment') }}</flux:heading>
                <flux:text class="mt-1">{{ __('Confirm only once the money is in the organization\'s account or hands. A confirmed payment settles the bills above and cannot be changed or deleted.') }}</flux:text>
                <form wire:submit="confirm" class="mt-4 flex flex-col gap-3 sm:max-w-xl" novalidate>
                    <flux:input wire:model="note" :label="__('Note (optional)')" maxlength="500" data-test="confirm-note" />
                    <div>
                        <flux:button type="submit" variant="primary" wire:confirm="{{ __('Confirm this payment? It cannot be undone here.') }}" data-test="confirm-payment">{{ __('Confirm payment') }}</flux:button>
                    </div>
                </form>
            </section>
        @endif

        @if ($canReject)
            <section class="rounded-xl border border-zinc-200 bg-white p-5 lg:col-span-3 dark:border-zinc-700 dark:bg-zinc-900" data-test="payment-reject">
                <flux:heading size="lg" level="2">{{ __('Reject this payment') }}</flux:heading>
                <flux:text class="mt-1">{{ __('When the money never arrived, or the payment was recorded twice. It stays on record with the reason.') }}</flux:text>
                <form wire:submit="reject" class="mt-4 flex flex-col gap-3 sm:max-w-xl" novalidate>
                    <flux:input wire:model="reason" :label="__('Reason')" maxlength="500" required data-test="reject-reason" />
                    <div>
                        <flux:button type="submit" variant="danger" wire:confirm="{{ __('Reject this payment?') }}" data-test="reject-payment">{{ __('Reject payment') }}</flux:button>
                    </div>
                </form>
            </section>
        @endif
    </div>
</x-page>
