{{--
    Match a statement credit by hand (Modules\Collections\Livewire\LineMatch).
    Amounts are typed in naira; the server keeps them as kobo and checks everything again.
--}}
<x-page :heading="__('Match a credit')" :description="$statement->accountLabel()">
    <x-slot:actions>
        <flux:button icon="arrow-left" :href="route('workspace.statements.show', [$organization, $statement->id])" wire:navigate>{{ __('Back to the statement') }}</flux:button>
    </x-slot:actions>

    @error('line')
        <flux:callout variant="warning" icon="exclamation-triangle" :heading="$message" role="alert" data-test="match-warning" />
    @enderror
    @error('payment')
        <flux:callout variant="warning" icon="exclamation-triangle" :heading="$message" role="alert" data-test="match-warning" />
    @enderror
    @error('billId')
        <flux:callout variant="warning" icon="exclamation-triangle" :heading="$message" role="alert" data-test="match-warning" />
    @enderror

    <section class="rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900" data-test="match-line">
        <flux:heading size="lg" level="2">{{ __('The credit') }}</flux:heading>
        <dl class="mt-4 grid gap-4 sm:grid-cols-3">
            <div>
                <dt class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Date') }}</dt>
                <dd class="text-zinc-900 dark:text-white">{{ $line->transaction_date->format('d/m/Y') }}</dd>
            </div>
            <div>
                <dt class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Amount') }}</dt>
                <dd class="font-medium text-emerald-700 dark:text-emerald-400" data-test="match-amount">{{ $line->amount_kobo->format() }}</dd>
            </div>
            <div class="sm:col-span-3">
                <dt class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Narration') }}</dt>
                <dd class="break-words text-zinc-900 dark:text-white">{{ $line->narration !== '' ? $line->narration : __('(no narration)') }}@if ($line->counterparty_name) · {{ $line->counterparty_name }}@endif</dd>
            </div>
        </dl>
    </section>

    @if (! $open)
        <flux:callout variant="secondary" icon="information-circle" :heading="__('This credit is matched already: :status.', ['status' => mb_strtolower(__($line->match_status->label()))])" data-test="match-closed">
            @if ($line->payment_id)
                <x-slot:actions>
                    <flux:button size="sm" :href="route('workspace.payments.show', [$organization, $line->payment_id])" wire:navigate>{{ __('Open the payment') }}</flux:button>
                </x-slot:actions>
            @endif
        </flux:callout>
    @else
        <form wire:submit="save" class="flex flex-col gap-6" novalidate>
            <section class="rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900">
                <flux:heading size="lg" level="2">{{ __('Whose payment') }}</flux:heading>

                <div class="mt-4 grid gap-5 sm:grid-cols-2">
                    <flux:select wire:model.live="tenantId" :label="__('Tenant')" required data-test="match-tenant">
                        <option value="">{{ __('Choose a tenant') }}</option>
                        @foreach ($tenants as $optionId => $optionName)
                            <option value="{{ $optionId }}">{{ $optionName }}</option>
                        @endforeach
                    </flux:select>
                </div>
                @error('tenantId')
                    <flux:text class="mt-2 text-red-600 dark:text-red-400" role="alert">{{ $message }}</flux:text>
                @enderror
            </section>

            @if ($tenantId !== '')
                <section class="rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900" data-test="match-bills">
                    <flux:heading size="lg" level="2">{{ __('Which bills it pays') }}</flux:heading>
                    <flux:text class="mt-1">{{ __('Type how much goes to each bill to split the credit. Leave them all empty to pay the tenant\'s open bills oldest first. Whatever is left becomes the tenant\'s credit.') }}</flux:text>

                    @if ($bills === [])
                        <flux:text class="mt-4" data-test="match-no-bills">{{ __('The tenant has no open bill: the whole credit becomes their credit, used on their next bills.') }}</flux:text>
                    @else
                        <ul class="mt-4 flex flex-col gap-3">
                            @foreach ($bills as $bill)
                                <li class="grid gap-2 sm:grid-cols-3 sm:items-center" wire:key="bill-{{ $bill->id }}" data-bill="{{ $bill->id }}">
                                    <span class="text-zinc-900 dark:text-white sm:col-span-2">{{ $bill->number }} · {{ __('owes :amount, due :date', ['amount' => $bill->outstanding->format(), 'date' => $bill->dueDate->format('d/m/Y')]) }}</span>
                                    <flux:input wire:model="amounts.{{ $bill->id }}" inputmode="decimal" size="sm" placeholder="0" maxlength="20" :aria-label="__('Amount for :number', ['number' => $bill->number])" data-test="match-amount-{{ $bill->id }}" />
                                    @error('amounts.'.$bill->id)
                                        <flux:text class="text-red-600 dark:text-red-400 sm:col-span-3" role="alert">{{ $message }}</flux:text>
                                    @enderror
                                </li>
                            @endforeach
                        </ul>
                        <flux:text class="mt-3 text-sm">{{ __('Open bills owe :owed in all.', ['owed' => $owed->format()]) }}</flux:text>
                    @endif
                </section>
            @endif

            <div class="flex flex-wrap gap-2">
                <flux:button type="submit" variant="primary" wire:confirm="{{ __('Record this credit as the tenant\'s payment and confirm it now? A receipt goes to the tenant, and a confirmed payment cannot be changed or deleted.') }}" data-test="save-match">{{ __('Record and confirm') }}</flux:button>
            </div>
        </form>
    @endif
</x-page>
