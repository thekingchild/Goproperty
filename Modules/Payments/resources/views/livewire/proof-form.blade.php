{{--
    Upload a proof of payment for a tenant (Modules\Payments\Livewire\ProofForm).
    Amounts are typed in naira; the server keeps them as kobo, checks the file
    (type sniffed from its bytes, size cap) and everything else again.
--}}
<x-page :heading="__('Upload a proof of payment')" :description="__('A transfer screenshot, a teller slip or a PDF a tenant sent :organization. It goes to the confirmation queue: confirm it once the money is in the account.', ['organization' => $organization->name])">
    <x-slot:actions>
        <flux:button icon="arrow-left" :href="route('workspace.payments.index', $organization)" wire:navigate>{{ __('All payments') }}</flux:button>
    </x-slot:actions>

    @error('proof')
        <flux:callout variant="warning" icon="exclamation-triangle" :heading="$message" role="alert" data-test="proof-warning" />
    @enderror

    <form wire:submit="save" class="flex flex-col gap-6" novalidate>
        <section class="rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900">
            <flux:heading size="lg" level="2">{{ __('Who paid, for what') }}</flux:heading>

            <div class="mt-4 grid gap-5 sm:grid-cols-2">
                <flux:select wire:model.live="tenantId" :label="__('Tenant')" required data-test="proof-tenant">
                    <option value="">{{ __('Choose a tenant') }}</option>
                    @foreach ($tenants as $optionId => $optionName)
                        <option value="{{ $optionId }}">{{ $optionName }}</option>
                    @endforeach
                </flux:select>

                <flux:select wire:model="billId" :label="__('Bill (optional)')" :description="__('Settled first once confirmed.')" data-test="proof-bill">
                    <option value="">{{ __('The tenant\'s open bills, oldest first') }}</option>
                    @foreach ($bills as $option)
                        <option value="{{ $option->id }}">{{ $option->number }} · {{ __('owes :amount, due :date', ['amount' => $option->outstanding->format(), 'date' => $option->dueDate->format('d/m/Y')]) }}</option>
                    @endforeach
                </flux:select>
            </div>
        </section>

        <section class="rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900">
            <flux:heading size="lg" level="2">{{ __('What the proof shows') }}</flux:heading>

            <div class="mt-4 grid gap-5 sm:grid-cols-2">
                <flux:input wire:model="amount" inputmode="decimal" :label="__('Amount (₦)')" placeholder="150,000" maxlength="20" required data-test="proof-amount" />
                <flux:input wire:model="paidOn" type="date" :label="__('Date paid')" required data-test="proof-paid-on" />

                <flux:input wire:model="senderName" :label="__('Sender\'s name')" :description="__('The name on the account the money came from.')" maxlength="150" required data-test="proof-sender" />
                <flux:input wire:model="bank" list="proof-banks" :label="__('Bank paid from')" maxlength="100" required data-test="proof-bank" />
                <datalist id="proof-banks">
                    @foreach ($banks as $bankName)
                        <option value="{{ $bankName }}"></option>
                    @endforeach
                </datalist>

                <flux:input wire:model="reference" :label="__('Narration or reference (optional)')" :description="__('As on the transfer: the bill reference, a session ID.')" maxlength="100" data-test="proof-reference" />
                <flux:input wire:model="note" :label="__('Note (optional)')" maxlength="500" data-test="proof-note" />

                <div class="sm:col-span-2">
                    <flux:input type="file" wire:model="file" accept="image/jpeg,image/png,image/webp,application/pdf" :label="__('Proof')" :description="__('JPEG, PNG, WebP or PDF, up to 10 MB.')" required data-test="proof-file" />
                </div>
            </div>
        </section>

        <div class="flex flex-wrap gap-2">
            <flux:button type="submit" variant="primary" data-test="save-proof">{{ __('Save for confirmation') }}</flux:button>
        </div>
    </form>
</x-page>
