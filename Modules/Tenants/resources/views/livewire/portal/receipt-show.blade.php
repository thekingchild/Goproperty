{{--
    One of my receipts (Modules\Tenants\Livewire\Portal\PortalReceiptShow,
    P03-11b): the agency's branding as issued, the amount, how it was paid
    and confirmed, the bills it paid, the verify link (UX_DESIGN.md § 1:
    trust signals), and the receipt as printed (web page, PDF, VOID copy)
    unless a GoProperty support session is open.
--}}
@php
    /** @var \Modules\Tenants\Portal\PortalReceiptPage $page */
    $receipt = $page->receipt;
    $printed = $receipt->document;
    $brand = $printed->brand;
@endphp
<x-page :heading="__('Receipt :number', ['number' => $printed->number])" :description="$printed->property->building.($printed->property->unit ? ' — '.$printed->property->unit : '')">
    <x-slot:actions>
        <flux:button icon="arrow-left" :href="route('portal.payments.index')" wire:navigate class="min-h-11">{{ __('My payments') }}</flux:button>
    </x-slot:actions>

    @include('tenants::portal.partials.nav')

    <section class="flex flex-col gap-4 overflow-hidden rounded-2xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900" style="border-top: 6px solid {{ $brand->primary() }}" data-test="portal-receipt-page" data-receipt="{{ $receipt->id }}">
        <header class="flex min-w-0 items-center gap-3">
            @if ($brand->logoSrc)
                <img src="{{ $brand->logoSrc }}" alt="{{ $brand->name() }}" class="h-12 max-w-40 shrink-0 object-contain">
            @else
                <span class="flex size-11 shrink-0 items-center justify-center rounded-lg font-bold text-white" style="background-color: {{ $brand->strong() }}" aria-hidden="true">{{ $brand->initials() }}</span>
            @endif
            <div class="min-w-0">
                <flux:heading size="lg" level="2" class="break-words">{{ $brand->name() }}</flux:heading>
                <flux:text class="text-sm">{{ __('Official receipt') }}</flux:text>
            </div>
        </header>

        @if ($receipt->voided)
            <flux:callout variant="danger" icon="x-circle" :heading="__('This receipt was voided')" data-test="portal-receipt-void"
                :text="trim(__(':agency voided it:date.', ['agency' => $brand->name(), 'date' => $printed->voidedAt ? ' '.__('on :date', ['date' => $printed->voidedAt->timezone('Africa/Lagos')->format('d/m/Y')]) : '']).' '.($printed->voidReason ?? ''))" />
        @endif

        <div class="flex flex-col gap-1 rounded-xl p-4 text-white" style="background-color: {{ $brand->strong() }}" data-test="portal-receipt-amount">
            <span class="text-sm opacity-90">{{ __('Amount received') }}</span>
            <span class="text-3xl font-bold break-words">{{ $printed->amount->format() }}</span>
            <span class="text-sm opacity-90">{{ __('Issued :date', ['date' => $printed->issuedAt->timezone('Africa/Lagos')->format('d/m/Y')]) }}</span>
        </div>

        <dl class="grid grid-cols-1 gap-3 sm:grid-cols-2">
            <div>
                <dt class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Paid') }}</dt>
                <dd class="text-zinc-900 dark:text-white">{{ __(':date · :method', ['date' => $printed->payment->paidOn->format('d/m/Y'), 'method' => __($printed->payment->method)]) }}</dd>
            </div>
            <div>
                <dt class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('How it was confirmed') }}</dt>
                <dd class="text-zinc-900 dark:text-white">{{ __($printed->payment->confirmation) }}</dd>
            </div>
            <div>
                <dt class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Confirmed by') }}</dt>
                <dd class="break-words text-zinc-900 dark:text-white" data-test="portal-receipt-confirmed-by">{{ $printed->confirmedBy }}</dd>
            </div>
            @if ($printed->payment->reference)
                <div>
                    <dt class="text-sm text-zinc-500 dark:text-zinc-400">{{ __($printed->payment->referenceLabel) }}</dt>
                    <dd class="break-all text-zinc-900 dark:text-white">{{ $printed->payment->reference }}</dd>
                </div>
            @endif
            <div>
                <dt class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Still to pay after this') }}</dt>
                <dd class="text-zinc-900 dark:text-white">{{ $printed->outstandingAfter->format() }}</dd>
            </div>
            @if ($printed->creditBalance->isPositive())
                <div>
                    <dt class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Your credit with :agency', ['agency' => $brand->name()]) }}</dt>
                    <dd class="text-zinc-900 dark:text-white">{{ $printed->creditBalance->format() }}</dd>
                </div>
            @endif
        </dl>

        @if ($printed->allocations !== [])
            <div class="flex flex-col gap-2">
                <flux:heading size="md" level="3">{{ __('What it paid') }}</flux:heading>
                <ul class="divide-y divide-zinc-100 dark:divide-zinc-800" data-test="portal-receipt-lines">
                    @foreach ($printed->allocations as $line)
                        @php($bill = collect($page->agency->bills)->first(fn ($bill) => $bill->number === $line->invoiceNumber))
                        <li class="flex flex-wrap justify-between gap-2 py-3">
                            <span class="min-w-0 break-words text-zinc-900 dark:text-white">
                                @if ($bill)
                                    <a href="{{ route('portal.bills.show', $bill->id) }}" wire:navigate class="inline-flex min-h-11 items-center underline">{{ $line->invoiceNumber }}</a>
                                @else
                                    {{ $line->invoiceNumber }}
                                @endif
                                @if ($line->description)<span class="block text-sm text-zinc-500 dark:text-zinc-400">{{ $line->description }}</span>@endif
                            </span>
                            <span class="font-semibold text-zinc-900 dark:text-white">{{ $line->amount->format() }}</span>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif
    </section>

    <section class="flex flex-col gap-3 rounded-2xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900" data-test="portal-receipt-verify">
        <flux:heading size="md" level="2">{{ __('Check this receipt') }}</flux:heading>
        <flux:text class="text-sm">{{ __('Anyone can check it is genuine with code :code on the verify page.', ['code' => $printed->verificationCode]) }}</flux:text>
        <flux:button :href="$receipt->verifyUrl" target="_blank" icon="shield-check" class="min-h-11 w-full sm:w-auto" data-test="portal-receipt-verify-link">{{ __('Open the verify page') }}</flux:button>
    </section>

    <section class="flex flex-col gap-3 rounded-2xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900" data-test="portal-receipt-files">
        <flux:heading size="md" level="2">{{ __('Your copy') }}</flux:heading>
        @if ($page->files)
            <div class="flex flex-col gap-2 sm:flex-row sm:flex-wrap">
                <flux:button :href="route('portal.receipts.document', $receipt->id)" target="_blank" icon="document-text" class="min-h-11 w-full sm:w-auto" data-test="portal-receipt-document">{{ __('View the receipt') }}</flux:button>
                <flux:button :href="route('portal.receipts.pdf', $receipt->id)" icon="arrow-down-tray" class="min-h-11 w-full sm:w-auto" data-test="portal-receipt-pdf">{{ __('Download the PDF') }}</flux:button>
                @if ($receipt->voided)
                    <flux:button :href="route('portal.receipts.void-pdf', $receipt->id)" icon="arrow-down-tray" class="min-h-11 w-full sm:w-auto" data-test="portal-receipt-void-pdf">{{ __('VOID copy (PDF)') }}</flux:button>
                @endif
            </div>
        @else
            <flux:text class="text-sm" data-test="portal-receipt-files-hidden">{{ __('The printed receipt shows your contact details, so it does not open in a GoProperty support session.') }}</flux:text>
        @endif
    </section>
</x-page>
