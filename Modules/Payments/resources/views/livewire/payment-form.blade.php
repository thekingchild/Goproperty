{{--
    Record a payment (Modules\Payments\Livewire\PaymentForm). Amounts are
    typed in naira; the server keeps them as kobo and checks everything again.
--}}
<x-page :heading="__('Record a payment')" :description="__('A bank transfer, cash or POS payment a tenant made to :organization. GoProperty only records it: the money is in your account or your hands.', ['organization' => $organization->name])">
    <x-slot:actions>
        <flux:button icon="arrow-left" :href="route('workspace.payments.index', $organization)" wire:navigate>{{ __('All payments') }}</flux:button>
    </x-slot:actions>

    @error('payment')
        <flux:callout variant="warning" icon="exclamation-triangle" :heading="$message" role="alert" data-test="payment-warning" />
    @enderror

    <form wire:submit="save" class="flex flex-col gap-6" novalidate>
        <section class="rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900">
            <flux:heading size="lg" level="2">{{ __('Who paid, for what') }}</flux:heading>

            <div class="mt-4 grid gap-5 sm:grid-cols-2">
                <flux:select wire:model.live="tenantId" :label="__('Tenant')" required data-test="payment-tenant">
                    <option value="">{{ __('Choose a tenant') }}</option>
                    @foreach ($tenants as $optionId => $optionName)
                        <option value="{{ $optionId }}">{{ $optionName }}</option>
                    @endforeach
                </flux:select>

                <flux:select wire:model="billId" :label="__('Bill (optional)')" :description="__('Settled first. Without one, the tenant\'s open bills are settled oldest first; anything left becomes credit.')" data-test="payment-bill">
                    <option value="">{{ __('The tenant\'s open bills, oldest first') }}</option>
                    @foreach ($bills as $option)
                        <option value="{{ $option->id }}">{{ $option->number }} · {{ __('owes :amount, due :date', ['amount' => $option->outstanding->format(), 'date' => $option->dueDate->format('d/m/Y')]) }}</option>
                    @endforeach
                </flux:select>
            </div>
        </section>

        <section class="rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900">
            <flux:heading size="lg" level="2">{{ __('The payment') }}</flux:heading>

            <div class="mt-4 grid gap-5 sm:grid-cols-2">
                <flux:input wire:model="amount" inputmode="decimal" :label="__('Amount (₦)')" placeholder="150,000" maxlength="20" required data-test="payment-amount" />
                <flux:input wire:model="paidAt" type="date" :label="__('Paid on')" required data-test="payment-paid-at" />

                <flux:select wire:model.live="method" :label="__('Method')" data-test="payment-method">
                    @foreach ($methods as $option)
                        <option value="{{ $option->value }}">{{ __($option->label()) }}</option>
                    @endforeach
                </flux:select>
                <flux:input wire:model="reference" :label="__('Reference (optional)')" :description="__('The transfer narration or session ID, a POS slip number.')" maxlength="100" data-test="payment-reference" />

                <flux:input wire:model="payerName" :label="__('Paid by (optional)')" :description="__('When someone other than the tenant paid.')" maxlength="150" data-test="payment-payer" />
                <flux:select wire:model="payerType" :label="__('Payer is')" data-test="payment-payer-type">
                    @foreach ($payerTypes as $option)
                        <option value="{{ $option->value }}">{{ __($option->label()) }}</option>
                    @endforeach
                </flux:select>

                <div class="sm:col-span-2">
                    <flux:textarea wire:model="note" :label="in_array($method, ['cash', 'pos'], true) ? __('Note') : __('Note (optional)')" :description="__('Cash and POS need a note: who received it, where.')" rows="2" maxlength="500" data-test="payment-note" />
                </div>
            </div>
        </section>

        <div class="flex flex-wrap gap-2">
            <flux:button type="submit" variant="primary" data-test="save-payment">{{ __('Record for confirmation') }}</flux:button>
            @if ($canConfirm)
                <flux:button type="button" wire:click="saveAndConfirm" wire:confirm="{{ __('Record and confirm this payment now? A confirmed payment cannot be changed or deleted.') }}" data-test="save-and-confirm">{{ __('Record and confirm') }}</flux:button>
            @endif
        </div>
    </form>
</x-page>
