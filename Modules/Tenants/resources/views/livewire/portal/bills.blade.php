{{--
    My bills (Modules\Tenants\Livewire\Portal\PortalBills, P03-11a): per
    agency, under that agency's logo as on its newest bill, the bills still
    to pay (due first first) and then the paid and void ones. Each bill
    keeps the branding it was issued with.
--}}
<x-page :heading="__('My bills')" :description="__('Every bill from every agency you rent from. Open a bill to see how to pay it.')">
    @if ($agencies === [])
        <x-empty-state icon="banknotes" :heading="__('No bills yet')" :description="__('When an agency adds you as a tenant, your bills appear here.')" />
    @else
        @include('tenants::portal.partials.nav')

        @foreach ($agencies as $agency)
            @php($toPay = $agency->toPay())
            @php($settled = $agency->settled())
            <section class="flex flex-col gap-4" data-test="portal-agency-bills" data-organization="{{ $agency->organizationId() }}">
                @include('tenants::portal.partials.agency', ['agency' => $agency, 'brand' => $agency->latestBill()?->brand])

                @if ($agency->bills === [])
                    <flux:text>{{ __('No bills from :agency yet.', ['agency' => $agency->name]) }}</flux:text>
                @else
                    @if ($toPay !== [])
                        <div class="flex flex-col gap-3" data-test="portal-to-pay">
                            <flux:heading size="md" level="3">{{ __('To pay: :amount', ['amount' => $agency->amountDue()->format()]) }}</flux:heading>
                            @foreach ($toPay as $bill)
                                @include('tenants::portal.partials.bill-card', ['bill' => $bill])
                            @endforeach
                        </div>
                    @else
                        <flux:text data-test="portal-nothing-due">{{ __('Nothing to pay right now.') }}</flux:text>
                    @endif

                    @if ($settled !== [])
                        <div class="flex flex-col gap-3" data-test="portal-settled">
                            <flux:heading size="md" level="3">{{ __('Paid and cancelled') }}</flux:heading>
                            @foreach ($settled as $bill)
                                @include('tenants::portal.partials.bill-card', ['bill' => $bill])
                            @endforeach
                        </div>
                    @endif
                @endif
            </section>
        @endforeach
    @endif
</x-page>
