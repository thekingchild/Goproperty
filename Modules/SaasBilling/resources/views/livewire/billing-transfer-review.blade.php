{{--
    The accept screen (/o/{organization}/billing-transfers/{transfer};
    Modules\SaasBilling\Livewire\BillingTransferReview), BILLING_PAYMENTS.md
    § 7 rule 2: the amount is stated before the owner accepts. Buttons show
    only while the offer is open and the user may answer it; Accept and
    Decline check the policy again.
--}}
@php
    $from = $transfer->organization;
    $date = fn ($value) => $value?->timezone('Africa/Lagos')->format('j F Y');
@endphp
<x-page :heading="__(':organization offers you its bill', ['organization' => $from->name])" :description="__('Offered by :name on :date.', ['name' => $transfer->initiatedBy?->name ?? __('a former member'), 'date' => $date($transfer->created_at)])">
    <x-slot:actions>
        <flux:button :href="route('workspace.billing-transfers.index', $organization)" wire:navigate icon="arrow-left">{{ __('Billing transfers') }}</flux:button>
    </x-slot:actions>

    @error('response')
        <flux:callout variant="danger" icon="x-circle" :heading="$message" role="alert" data-test="response-error" />
    @enderror

    @if ($bill !== null)
        <section class="rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900" aria-labelledby="statement-heading">
            <flux:heading size="lg" level="2" id="statement-heading" data-test="transfer-statement">
                {{ __('You are taking over the GoProperty subscription bill for :amount/month from :date.', ['amount' => $bill->amount->format(), 'date' => $date(now())]) }}
            </flux:heading>
            <flux:text class="mt-2">
                {{ __(':organization is on the :plan plan with :units units: :base a month for up to :included units, plus :extra for each of :count more.', [
                    'organization' => $from->name,
                    'plan' => $bill->plan->name,
                    'units' => $bill->units,
                    'base' => $bill->plan->base->format(),
                    'included' => $bill->plan->includedUnits,
                    'extra' => $bill->plan->extraUnit?->format() ?? __('a custom price'),
                    'count' => $bill->extraUnits(),
                ]) }}
            </flux:text>
            <flux:text class="mt-1">{{ __(':vat% VAT is added on each invoice. The amount follows their unit count on each billing day.', ['vat' => $bill->vatPercent]) }}</flux:text>
            <flux:text class="mt-1">{{ __('Accepting moves only the bill: you get no access to :organization\'s buildings, tenants or money. The offer expires on :date.', ['organization' => $from->name, 'date' => $date($transfer->expires_at)]) }}</flux:text>

            @if ($canRespond)
                <div class="mt-4 flex flex-wrap gap-2">
                    <flux:button variant="primary" wire:click="accept" wire:confirm="{{ __('Take over :organization\'s GoProperty bill for :amount a month?', ['organization' => $from->name, 'amount' => $bill->amount->format()]) }}" data-test="accept-button">{{ __('Accept and take over the bill') }}</flux:button>
                    <flux:button variant="danger" wire:click="decline" wire:confirm="{{ __('Decline :organization\'s offer?', ['organization' => $from->name]) }}" data-test="decline-button">{{ __('Decline') }}</flux:button>
                </div>
            @endif
        </section>
    @else
        <flux:callout icon="information-circle" data-test="transfer-closed"
            :heading="match (true) {
                $transfer->status === \Modules\SaasBilling\Enums\BillingTransferStatus::Completed => __('You accepted this offer on :date.', ['date' => $date($transfer->responded_at)]),
                $transfer->status === \Modules\SaasBilling\Enums\BillingTransferStatus::Declined => __('You declined this offer on :date.', ['date' => $date($transfer->responded_at)]),
                $transfer->status === \Modules\SaasBilling\Enums\BillingTransferStatus::Cancelled => __(':organization withdrew this offer.', ['organization' => $from->name]),
                default => __('This offer expired on :date.', ['date' => $date($transfer->expires_at)]),
            }" />
    @endif
</x-page>
