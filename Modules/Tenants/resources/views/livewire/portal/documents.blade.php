{{--
    My documents (Modules\Tenants\Livewire\Portal\PortalDocuments, P03-11b):
    per agency, the tenancy agreements, invoices and receipts as issued,
    each a download (signed, short-lived link after the portal's policy
    check). In a GoProperty support session the list shows but nothing
    opens. Rows, not tables (360 px); every row is a 44 px target.
--}}
<x-page :heading="__('My documents')" :description="__('Your tenancy agreements, invoices and receipts, as each agency issued them.')">
    @include('tenants::portal.partials.nav')

    @if ($agencies === [])
        <x-empty-state icon="document-text" :heading="__('No documents yet')" :description="__('When an agency adds you as a tenant, your agreement, invoices and receipts appear here.')" />
    @else
        @foreach ($agencies as $records)
            @php($agency = $records->agency)
            @php($contracts = $records->contracts())
            <section class="flex flex-col gap-4" data-test="portal-agency-documents" data-organization="{{ $agency->organizationId() }}">
                @include('tenants::portal.partials.agency', ['agency' => $agency, 'brand' => $agency->latestBill()?->brand])

                @if (! $records->files)
                    <flux:callout variant="warning" icon="lock-closed" :text="__('Documents show your contact details, so they do not open in a GoProperty support session.')" data-test="portal-documents-locked" />
                @endif

                @if ($records->isEmpty())
                    <flux:text>{{ __('No documents from :agency yet.', ['agency' => $agency->name]) }}</flux:text>
                @endif

                @if ($contracts !== [])
                    <div class="flex flex-col gap-2" data-test="portal-contracts">
                        <flux:heading size="md" level="3">{{ __('Tenancy agreements') }}</flux:heading>
                        <ul class="flex flex-col gap-2">
                            @foreach ($contracts as $home)
                                @include('tenants::portal.partials.document-row', [
                                    'url' => $records->files ? route('portal.leases.contract', $home->lease->id) : null,
                                    'title' => __('Agreement :number', ['number' => $home->lease->contractNumber]),
                                    'detail' => $home->unit.' · '.$home->building.' · '.$home->lease->startDate->format('d/m/Y').' – '.$home->lease->endDate->format('d/m/Y'),
                                    'test' => 'portal-contract',
                                    'id' => $home->lease->id,
                                ])
                            @endforeach
                        </ul>
                    </div>
                @endif

                @if ($agency->bills !== [])
                    <div class="flex flex-col gap-2" data-test="portal-invoices">
                        <flux:heading size="md" level="3">{{ __('Invoices') }}</flux:heading>
                        <ul class="flex flex-col gap-2">
                            @foreach ($agency->bills as $bill)
                                @include('tenants::portal.partials.document-row', [
                                    'url' => $records->files ? route('portal.bills.invoice-pdf', $bill->id) : null,
                                    'title' => $bill->isVoid() ? __('Invoice :number (VOID copy)', ['number' => $bill->number]) : __('Invoice :number', ['number' => $bill->number]),
                                    'detail' => __(':type · :amount · issued :date', ['type' => __($bill->typeLabel), 'amount' => $bill->amount->format(), 'date' => $bill->issuedAt->timezone('Africa/Lagos')->format('d/m/Y')]),
                                    'test' => 'portal-invoice-document',
                                    'id' => $bill->id,
                                ])
                            @endforeach
                        </ul>
                    </div>
                @endif

                @if ($records->receipts !== [])
                    <div class="flex flex-col gap-2" data-test="portal-receipt-documents">
                        <flux:heading size="md" level="3">{{ __('Receipts') }}</flux:heading>
                        <ul class="flex flex-col gap-2">
                            @foreach ($records->receipts as $receipt)
                                @include('tenants::portal.partials.document-row', [
                                    'url' => $records->files ? route('portal.receipts.pdf', $receipt->id) : null,
                                    'title' => __('Receipt :number', ['number' => $receipt->number()]),
                                    'detail' => __(':amount · issued :date', ['amount' => $receipt->document->amount->format(), 'date' => $receipt->document->issuedAt->timezone('Africa/Lagos')->format('d/m/Y')]).($receipt->voided ? ' · '.__('voided') : ''),
                                    'test' => 'portal-receipt-document',
                                    'id' => $receipt->id,
                                ])
                                @if ($receipt->voided)
                                    @include('tenants::portal.partials.document-row', [
                                        'url' => $records->files ? route('portal.receipts.void-pdf', $receipt->id) : null,
                                        'title' => __('Receipt :number (VOID copy)', ['number' => $receipt->number()]),
                                        'detail' => __('Printed when the receipt was voided'),
                                        'test' => 'portal-receipt-void-document',
                                        'id' => $receipt->id,
                                    ])
                                @endif
                            @endforeach
                        </ul>
                    </div>
                @endif
            </section>
        @endforeach
    @endif
</x-page>
