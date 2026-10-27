{{--
    My payments (Modules\Tenants\Livewire\Portal\PortalPayments, P03-11b):
    per agency, the receipts issued to the tenant (each opens its page with
    the verify link and the PDF) and the proofs of payment sent and where
    each stands. Cards, no tables (360 px).
--}}
<x-page :heading="__('My payments')" :description="__('Your receipts and the proofs of payment you sent, from every agency you rent from.')">
    @include('tenants::portal.partials.nav')

    @if ($agencies === [])
        <x-empty-state icon="receipt-percent" :heading="__('No payments yet')" :description="__('When an agency confirms a payment, your receipt appears here.')" />
    @else
        @foreach ($agencies as $records)
            @php($agency = $records->agency)
            <section class="flex flex-col gap-4" data-test="portal-agency-payments" data-organization="{{ $agency->organizationId() }}">
                @include('tenants::portal.partials.agency', ['agency' => $agency, 'brand' => $records->receipts[0]->document->brand ?? $agency->latestBill()?->brand])

                <div class="flex flex-col gap-3" data-test="portal-receipts">
                    <flux:heading size="md" level="3">{{ __('Receipts') }}</flux:heading>
                    @forelse ($records->receipts as $receipt)
                        @include('tenants::portal.partials.receipt-card', ['receipt' => $receipt])
                    @empty
                        <flux:text>{{ __('No receipts from :agency yet.', ['agency' => $agency->name]) }}</flux:text>
                    @endforelse
                </div>

                <div class="flex flex-col gap-3" data-test="portal-proofs">
                    <flux:heading size="md" level="3">{{ __('Proofs of payment') }}</flux:heading>
                    @if ($records->proofs === [])
                        <flux:text>{{ __('No proofs sent. Paid by transfer? Open the bill and send your proof.') }}</flux:text>
                    @else
                        <ul class="flex flex-col gap-3">
                            @foreach ($records->proofs as $proof)
                                @include('tenants::portal.partials.proof', ['proof' => $proof, 'files' => $records->files, 'billNumber' => $agency->bill($proof->billId)?->number])
                            @endforeach
                        </ul>
                    @endif
                </div>
            </section>
        @endforeach
    @endif
</x-page>
