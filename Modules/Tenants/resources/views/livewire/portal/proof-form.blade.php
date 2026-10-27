{{--
    Send a proof of payment for one of my bills
    (Modules\Tenants\Livewire\Portal\PortalProofForm, P03-11b): what the
    transfer shows, and the file. Amounts are typed in naira; the server
    keeps kobo, checks the file from its bytes and everything else again.
    Refusals (wrong file, too many proofs) show as plain messages.
--}}
@php
    /** @var \Modules\Tenants\Portal\PortalProofPage $page */
    $bill = $page->bill;
    $brand = $bill->brand;
@endphp
<x-page :heading="__('Send a proof of payment')" :description="__('Bill :number from :agency. They check it against their account, then send your receipt.', ['number' => $bill->number, 'agency' => $brand->name()])">
    <x-slot:actions>
        <flux:button icon="arrow-left" :href="route('portal.bills.show', $bill->id)" wire:navigate class="min-h-11" data-test="portal-proof-back">{{ __('Back to the bill') }}</flux:button>
    </x-slot:actions>

    @include('tenants::portal.partials.nav')

    @if ($page->agency->readOnly())
        <flux:callout variant="warning" icon="lock-closed" :text="__(':agency\'s account is on hold, but you can still send your proof.', ['agency' => $page->agency->name])" data-test="portal-read-only" />
    @endif

    @if (! $page->maySubmit)
        <flux:callout variant="warning" icon="exclamation-triangle" role="alert" data-test="portal-proof-closed"
            :heading="$bill->isVoid() ? __('This bill is cancelled: do not pay it') : ($bill->asksForMoney() ? __('Proofs cannot be sent from here right now') : __('This bill is paid'))"
            :text="$bill->asksForMoney() ? __('A GoProperty support session cannot send a proof for you.') : __('There is nothing to send a proof for.')" />
    @else
        @error('proof')
            <flux:callout variant="warning" icon="exclamation-triangle" :heading="$message" role="alert" data-test="portal-proof-warning" />
        @enderror

        <form wire:submit="save" class="flex flex-col gap-5" novalidate data-test="portal-proof-form">
            <section class="flex flex-col gap-4 rounded-2xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900" style="border-top: 6px solid {{ $brand->primary() }}">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <flux:heading size="lg" level="2" class="break-all">{{ $bill->number }}</flux:heading>
                    <span class="text-sm text-zinc-600 dark:text-zinc-300">{{ __('To pay: :amount', ['amount' => $bill->outstanding->format()]) }}</span>
                </div>

                <flux:input wire:model="amount" inputmode="decimal" :label="__('Amount you paid (₦)')" placeholder="150,000" maxlength="20" required class:input="min-h-11" data-test="proof-amount" />
                <flux:input wire:model="paidOn" type="date" :label="__('Date you paid')" required class:input="min-h-11" data-test="proof-paid-on" />
                <flux:input wire:model="senderName" :label="__('Sender\'s name')" :description="__('The name on the account the money came from.')" maxlength="150" required class:input="min-h-11" data-test="proof-sender" />
                <flux:input wire:model="bank" list="portal-proof-banks" :label="__('Bank you paid from')" maxlength="100" required class:input="min-h-11" data-test="proof-bank" />
                <datalist id="portal-proof-banks">
                    @foreach ($banks as $bankName)
                        <option value="{{ $bankName }}"></option>
                    @endforeach
                </datalist>
                <flux:input wire:model="reference" :label="__('Narration or reference (optional)')" :description="__('As on your transfer, for example :reference.', ['reference' => $bill->paymentReference])" maxlength="100" class:input="min-h-11" data-test="proof-reference" />
                <flux:input wire:model="note" :label="__('Note for the agency (optional)')" maxlength="500" class:input="min-h-11" data-test="proof-note" />
            </section>

            <section class="flex flex-col gap-3 rounded-2xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
                <flux:heading size="md" level="2">{{ __('Your proof') }}</flux:heading>
                <flux:text class="text-sm">{{ __('A screenshot of the transfer, a photo of the teller slip or a PDF: JPEG, PNG, WebP or PDF, up to 10 MB.') }}</flux:text>
                <label class="flex min-h-11 cursor-pointer flex-col gap-2">
                    <span class="sr-only">{{ __('Proof file') }}</span>
                    <input type="file" wire:model="file" accept="image/jpeg,image/png,image/webp,application/pdf" required
                        class="block min-h-11 w-full max-w-full rounded-lg border border-zinc-200 bg-white text-sm text-zinc-700 file:me-3 file:min-h-11 file:border-0 file:bg-zinc-100 file:px-4 file:font-medium file:text-zinc-800 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-200 dark:file:bg-zinc-800 dark:file:text-zinc-100"
                        data-test="proof-file">
                </label>
                <div wire:loading wire:target="file" class="text-sm text-zinc-600 dark:text-zinc-300">{{ __('Uploading…') }}</div>
                @error('file')
                    <flux:text class="text-sm text-red-600 dark:text-red-400" role="alert" data-test="proof-file-error">{{ $message }}</flux:text>
                @enderror
            </section>

            <flux:button type="submit" variant="primary" icon="paper-airplane" class="min-h-11 w-full sm:w-auto" wire:loading.attr="disabled" wire:target="save,file" data-test="send-proof">{{ __('Send proof') }}</flux:button>
            <flux:text class="text-center text-xs">{{ __('Sending a proof does not move money. :agency confirms your payment once it is in their account.', ['agency' => $brand->name()]) }}</flux:text>
        </form>
    @endif
</x-page>
