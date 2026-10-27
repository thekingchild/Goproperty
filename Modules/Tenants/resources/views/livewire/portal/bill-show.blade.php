{{--
    One of my bills (Modules\Tenants\Livewire\Portal\PortalBillShow,
    P03-11a): the agency's branding as the bill was issued, the amount to
    pay, the lines and how to pay — bank transfer with the payment reference
    for the narration, the online options (Billing's PaymentOptions slot)
    and the public pay page to share. A void bill says not to pay it.
    P03-11b: "Send a proof" (also while the agency is suspended), the
    proofs sent for this bill and where each stands, the invoice PDF.
--}}
@php
    /** @var \Modules\Tenants\Portal\PortalBillPage $page */
    $agency = $page->agency;
    $payment = $page->payment;
    $bill = $payment->bill;
    $brand = $bill->brand;
@endphp
<x-page :heading="__('Bill :number', ['number' => $bill->number])" :description="$bill->property">
    <x-slot:actions>
        <flux:button icon="arrow-left" :href="route('portal.bills.index')" wire:navigate class="min-h-11">{{ __('My bills') }}</flux:button>
        @if ($page->invoice)
            <flux:button icon="document-text" :href="route('portal.bills.invoice', $bill->id)" target="_blank" class="min-h-11" data-test="portal-invoice">{{ __('Invoice') }}</flux:button>
        @endif
        @if ($page->files)
            <flux:button icon="arrow-down-tray" :href="route('portal.bills.invoice-pdf', $bill->id)" class="min-h-11" data-test="portal-invoice-pdf">{{ $bill->isVoid() ? __('VOID copy (PDF)') : __('Invoice (PDF)') }}</flux:button>
        @endif
    </x-slot:actions>

    @include('tenants::portal.partials.nav')

    @if (session('portal-status'))
        <flux:callout variant="success" icon="check-circle" :heading="session('portal-status')" role="status" data-test="portal-status" />
    @endif

    <section class="flex flex-col gap-4 overflow-hidden rounded-2xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900" style="border-top: 6px solid {{ $brand->primary() }}" data-test="portal-bill-page" data-bill="{{ $bill->id }}">
        <header class="flex min-w-0 items-center gap-3" data-test="portal-bill-brand">
            @if ($brand->logoSrc)
                <img src="{{ $brand->logoSrc }}" alt="{{ $brand->name() }}" class="h-12 max-w-40 shrink-0 object-contain">
            @else
                <span class="flex size-11 shrink-0 items-center justify-center rounded-lg font-bold text-white" style="background-color: {{ $brand->strong() }}" aria-hidden="true">{{ $brand->initials() }}</span>
            @endif
            <div class="min-w-0">
                <flux:heading size="lg" level="2" class="break-words">{{ $brand->name() }}</flux:heading>
                @if ($brand->branding->phone || $brand->branding->email)
                    <flux:text class="text-sm break-words">{{ implode(' · ', array_filter([$brand->branding->phone, $brand->branding->email])) }}</flux:text>
                @endif
            </div>
        </header>

        @if ($agency->readOnly())
            <flux:callout variant="warning" icon="lock-closed" :text="__('Read-only for now: :agency\'s account is on hold. You can still see this bill, pay by bank transfer and send your proof.', ['agency' => $agency->name])" data-test="portal-read-only" />
        @endif

        <div class="flex flex-wrap items-center gap-2">
            <flux:heading size="md" level="3">{{ __($bill->typeLabel) }}</flux:heading>
            <flux:badge size="sm" :color="$bill->statusColor" data-test="portal-bill-status">{{ __($bill->statusLabel) }}</flux:badge>
        </div>

        @if ($bill->isVoid())
            <flux:callout variant="danger" icon="x-circle" :heading="__('This bill is cancelled: do not pay it')" :text="trim(__(':agency cancelled it:date.', ['agency' => $brand->name(), 'date' => $bill->voidedAt ? ' '.__('on :date', ['date' => $bill->voidedAt->timezone('Africa/Lagos')->format('d/m/Y')]) : '']).' '.($payment->voidReason ?? ''))" data-test="portal-bill-void" />
        @elseif ($bill->asksForMoney())
            <div class="flex flex-col gap-1 rounded-xl p-4 text-white" style="background-color: {{ $brand->strong() }}" data-test="portal-bill-outstanding">
                <span class="text-sm opacity-90">{{ __('Amount to pay') }}</span>
                <span class="text-3xl font-bold break-words">{{ $bill->outstanding->format() }}</span>
                <span class="text-sm opacity-90">{{ __('Due :date', ['date' => $bill->dueDate->format('d/m/Y')]) }}</span>
            </div>
        @else
            <flux:callout variant="success" icon="check-circle" :heading="__('This bill is paid')" :text="__('Nothing is owed on this bill. Keep your receipt from :agency.', ['agency' => $brand->name()])" data-test="portal-bill-paid" />
        @endif

        <dl class="grid grid-cols-1 gap-3 sm:grid-cols-2">
            <div>
                <dt class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Issued') }}</dt>
                <dd class="text-zinc-900 dark:text-white">{{ $bill->issuedAt->timezone('Africa/Lagos')->format('d/m/Y') }}</dd>
            </div>
            <div>
                <dt class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Due') }}</dt>
                <dd class="text-zinc-900 dark:text-white" data-test="portal-bill-due-date">{{ $bill->dueDate->format('d/m/Y') }}</dd>
            </div>
            @if ($bill->periodStart && $bill->periodEnd)
                <div>
                    <dt class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Period') }}</dt>
                    <dd class="text-zinc-900 dark:text-white">{{ $bill->periodStart->format('d/m/Y') }} – {{ $bill->periodEnd->format('d/m/Y') }}</dd>
                </div>
            @endif
            <div>
                <dt class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Total') }}</dt>
                <dd class="text-zinc-900 dark:text-white">{{ $bill->amount->format() }}</dd>
            </div>
            <div>
                <dt class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Paid so far') }}</dt>
                <dd class="text-zinc-900 dark:text-white" data-test="portal-bill-paid-so-far">{{ $bill->paid->format() }}</dd>
            </div>
            @if ($bill->leaseId !== null)
                <div>
                    <dt class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Lease') }}</dt>
                    <dd><a href="{{ route('portal.leases.show', $bill->leaseId) }}" wire:navigate class="inline-flex min-h-11 items-center text-zinc-900 underline dark:text-white">{{ __('Open the lease') }}</a></dd>
                </div>
            @endif
        </dl>

        <div class="flex flex-col gap-2">
            <flux:heading size="md" level="3">{{ __('What this bill is for') }}</flux:heading>
            <ul class="divide-y divide-zinc-100 dark:divide-zinc-800" data-test="portal-bill-lines">
                @foreach ($payment->lines as $line)
                    <li class="flex flex-wrap justify-between gap-2 py-3">
                        <span class="min-w-0 break-words text-zinc-900 dark:text-white">{{ $line['label'] }}
                            @if ($line['period'])<span class="block text-sm text-zinc-500 dark:text-zinc-400">{{ $line['period'] }}</span>@endif
                            @if ($line['note'])<span class="block text-sm text-zinc-500 dark:text-zinc-400">{{ $line['note'] }}</span>@endif
                        </span>
                        <span class="font-semibold text-zinc-900 dark:text-white">{{ $line['amount'] }}</span>
                    </li>
                @endforeach
            </ul>
        </div>
    </section>

    @if ($bill->asksForMoney())
        <section class="flex flex-col gap-3 rounded-2xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900" data-test="portal-bill-bank">
            <flux:heading size="lg" level="2">{{ __('Pay by bank transfer') }}</flux:heading>
            @if ($payment->bank)
                <dl class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                    <div>
                        <dt class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Bank') }}</dt>
                        <dd class="font-medium break-words text-zinc-900 dark:text-white">{{ $payment->bank->bank }}</dd>
                    </div>
                    <div>
                        <dt class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Account name') }}</dt>
                        <dd class="font-medium break-words text-zinc-900 dark:text-white">{{ $payment->bank->accountName }}</dd>
                    </div>
                    <div>
                        <dt class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Account number') }}</dt>
                        <dd class="font-mono text-lg font-semibold text-zinc-900 dark:text-white" data-test="portal-bill-account">{{ $payment->bank->accountNumber }}</dd>
                    </div>
                    <div>
                        <dt class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Narration') }}</dt>
                        <dd class="font-mono text-lg font-semibold tracking-wider break-all" style="color: {{ $brand->strong() }}" data-test="portal-bill-reference">{{ $bill->paymentReference }}</dd>
                    </div>
                </dl>
                <flux:text class="text-sm">{{ __('Put :reference in your transfer narration so :agency can match your payment to this bill.', ['reference' => $bill->paymentReference, 'agency' => $brand->name()]) }}</flux:text>
            @else
                <flux:text data-test="portal-bill-no-bank">{{ __(':agency has not added a bank account yet. Contact them for payment details and quote :reference.', ['agency' => $brand->name(), 'reference' => $bill->paymentReference]) }}</flux:text>
            @endif
        </section>

        @if ($payment->options !== [])
            <section class="flex flex-col gap-3 rounded-2xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900" data-test="portal-bill-online">
                <flux:heading size="lg" level="2">{{ __('Pay online') }}</flux:heading>
                @foreach ($payment->options as $option)
                    {!! $option !!}
                @endforeach
            </section>
        @endif

        <section class="flex flex-col gap-3 rounded-2xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900" data-test="portal-bill-proof">
            <flux:heading size="md" level="2">{{ __('Paid already?') }}</flux:heading>
            @if ($page->mayUploadProof)
                <flux:text class="text-sm">{{ __('Send :agency your transfer screenshot or teller slip. They confirm it against their account and send your receipt.', ['agency' => $brand->name()]) }}</flux:text>
                <flux:button :href="route('portal.bills.proof', $bill->id)" wire:navigate variant="primary" icon="arrow-up-tray" class="min-h-11 w-full sm:w-auto" data-test="portal-send-proof">{{ __('Send a proof of payment') }}</flux:button>
            @else
                <flux:text class="text-sm" data-test="portal-proof-unavailable">{{ __('A GoProperty support session cannot send a proof for you.') }}</flux:text>
            @endif
        </section>

        <section class="flex flex-col gap-3 rounded-2xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900" data-test="portal-bill-share">
            <flux:heading size="md" level="2">{{ __('Someone else paying?') }}</flux:heading>
            <flux:text class="text-sm">{{ __('Share this bill\'s pay page. It shows how to pay, never your contact details.') }}</flux:text>
            <flux:button :href="$payment->payUrl" icon="arrow-top-right-on-square" class="min-h-11 w-full sm:w-auto" data-test="portal-pay-page">{{ __('Open the pay page') }}</flux:button>
        </section>
    @endif

    @if ($page->proofs !== [])
        <section class="flex flex-col gap-3 rounded-2xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900" data-test="portal-bill-proofs">
            <flux:heading size="md" level="2">{{ __('Proofs sent for this bill') }}</flux:heading>
            <ul class="flex flex-col gap-3">
                @foreach ($page->proofs as $proof)
                    @include('tenants::portal.partials.proof', ['proof' => $proof, 'files' => $page->files, 'billNumber' => null])
                @endforeach
            </ul>
        </section>
    @endif

    <flux:text class="text-center text-xs">{{ __('Money goes straight to :agency\'s own account.', ['agency' => $brand->name()]) }}</flux:text>
</x-page>
