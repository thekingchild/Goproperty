{{--
    Settings → Subscription (App\Livewire\Settings\SubscriptionPage, P06-01b;
    BILLING_PAYMENTS.md § 1–2): the plan, its state, today's bill, a
    scheduled change (30 days' notice), what the plan includes, the other
    plans and GoProperty's invoices (P06-02), each open one with Pay
    (P06-03) and GoProperty's account for a bank transfer with the proof
    form (P06-04). Cards stack on phones
    (UX_DESIGN.md § 6).
--}}
<x-page :heading="__('Settings')" :description="__('Your organization\'s details, how tenants pay you, and how your documents look.')">
    <x-workspace.settings-nav current="workspace.settings.subscription" />

    @if ($notice)
        <flux:callout variant="success" icon="check-circle" :heading="$notice" data-test="notice" />
    @endif

    @if ($status)
        <flux:callout :variant="$status[0] === 'success' ? 'success' : 'warning'" :icon="$status[0] === 'success' ? 'check-circle' : 'exclamation-triangle'" :heading="$status[1]" data-test="payment-status" />
    @endif

    @error('pay')
        <flux:callout variant="danger" icon="x-circle" :heading="$message" data-test="pay-refused" />
    @enderror

    @error('plan')
        <flux:callout variant="danger" icon="x-circle" :heading="$message" data-test="plan-refused" />
    @enderror

    @if ($change)
        <flux:callout variant="warning" icon="calendar-days" :heading="__(':plan changes on :date', ['plan' => $change['plan']->name, 'date' => $change['date']->format('j F Y')])" data-test="scheduled-change">
            <flux:callout.text>
                <ul class="list-disc ps-5">
                    @forelse ($change['lines'] as $line)
                        <li>{{ $line }}</li>
                    @empty
                        <li>{{ __('No change to what you pay or what is included.') }}</li>
                    @endforelse
                </ul>
                <span class="mt-2 block">{{ __('At today\'s :units units your monthly bill goes from :before to :after before VAT, from your first billing period on or after that date.', ['units' => number_format($units), 'before' => $monthly?->format() ?? '—', 'after' => $change['plan']->priceFor($units)->format()]) }}</span>
            </flux:callout.text>
        </flux:callout>
    @endif

    <section class="rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900" data-test="subscription">
        <flux:heading size="lg" level="2">{{ __('Your plan') }}</flux:heading>

        @if ($plan === null)
            <flux:text class="mt-1">{{ __('No plan chosen yet. An owner chooses one while setting up the organization.') }}</flux:text>
        @else
            <dl class="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-3">
                <div class="rounded-lg bg-zinc-50 p-4 dark:bg-zinc-800">
                    <dt class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Plan') }}</dt>
                    <dd class="mt-1 text-2xl font-semibold text-zinc-900 dark:text-white" data-test="plan-name">{{ $plan->name }}</dd>
                    @if ($subscription)
                        <dd class="mt-1"><flux:badge size="sm" data-test="status">{{ __($subscription->status->label()) }}</flux:badge></dd>
                    @endif
                </div>
                <div class="rounded-lg bg-zinc-50 p-4 dark:bg-zinc-800">
                    <dt class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Monthly, at today\'s :units units', ['units' => number_format($units)]) }}</dt>
                    <dd class="mt-1 text-2xl font-semibold text-zinc-900 dark:text-white" data-test="monthly">{{ $monthly?->format() }}</dd>
                    <dd class="text-sm text-zinc-500 dark:text-zinc-400">{{ __(':base base for :included units, :extra each extra unit; :vat% VAT on top', ['base' => $plan->base->format(), 'included' => number_format($plan->includedUnits), 'extra' => $plan->extraUnit?->format() ?? __('custom'), 'vat' => $vat]) }}</dd>
                </div>
                <div class="rounded-lg bg-zinc-50 p-4 dark:bg-zinc-800">
                    @if ($subscription?->status === \App\Enums\SubscriptionStatus::Trialing)
                        <dt class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Free trial') }}</dt>
                        <dd class="mt-1 text-2xl font-semibold text-zinc-900 dark:text-white" data-test="trial-left">{{ trans_choice(':count day left|:count days left', $subscription->trialDaysLeft(), ['count' => $subscription->trialDaysLeft()]) }}</dd>
                        <dd class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Ends :date', ['date' => $subscription->trial_ends_at?->timezone('Africa/Lagos')->format('j F Y')]) }}</dd>
                    @else
                        <dt class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Staff users') }}</dt>
                        <dd class="mt-1 text-2xl font-semibold text-zinc-900 dark:text-white" data-test="staff-used">{{ $staffLimit === null ? number_format($staffUsed) : __(':used of :limit', ['used' => number_format($staffUsed), 'limit' => number_format($staffLimit)]) }}</dd>
                    @endif
                </div>
            </dl>

            <flux:heading size="md" level="3" class="mt-6">{{ __('What your plan includes') }}</flux:heading>
            <dl class="mt-2 divide-y divide-zinc-100 text-sm dark:divide-zinc-800" data-test="features">
                @foreach ($features as $feature)
                    <div class="flex items-center justify-between gap-4 py-2" data-feature="{{ $feature['key'] }}">
                        <dt class="text-zinc-600 dark:text-zinc-300">{{ $feature['label'] }}</dt>
                        <dd class="text-end font-medium text-zinc-900 dark:text-white">{{ __($feature['value']) }}@if ($feature['key'] === 'staff_users') <span class="font-normal text-zinc-500">· {{ __(':count in use', ['count' => number_format($staffUsed)]) }}</span>@endif</dd>
                    </div>
                @endforeach
            </dl>
        @endif
    </section>

    @if ($plan !== null && $choices !== [])
        <section class="rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900" data-test="plans">
            <flux:heading size="lg" level="2">{{ __('Plans at your :units units', ['units' => number_format($units)]) }}</flux:heading>
            <flux:text class="mt-1">{{ __('A move takes effect at once; your next invoice uses the new plan. Monthly prices before VAT.') }}</flux:text>

            <div class="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-3">
                @foreach ($choices as $choice)
                    <div class="rounded-lg border p-4 {{ $choice->key === $plan->key ? 'border-zinc-900 dark:border-white' : 'border-zinc-200 dark:border-zinc-700' }}" data-plan="{{ $choice->key }}">
                        <div class="font-semibold text-zinc-900 dark:text-white">{{ $choice->name }}</div>
                        <div class="mt-1 text-xl font-semibold text-zinc-900 dark:text-white">{{ $choice->priceFor($units)->format() }}</div>
                        <div class="text-sm text-zinc-500 dark:text-zinc-400">{{ __(':included units included, :extra each extra', ['included' => number_format($choice->includedUnits), 'extra' => $choice->extraUnit?->format() ?? __('custom')]) }}</div>
                        @if ($choice->key === $plan->key)
                            <flux:badge size="sm" class="mt-3">{{ __('Your plan') }}</flux:badge>
                        @elseif ($canChange)
                            <flux:button size="sm" class="mt-3" wire:click="changePlan('{{ $choice->key }}')" wire:confirm="{{ __('Move to :plan now?', ['plan' => $choice->name]) }}" data-test="move-{{ $choice->key }}">{{ __('Move to :plan', ['plan' => $choice->name]) }}</flux:button>
                        @endif
                    </div>
                @endforeach
            </div>
        </section>
    @endif

    @if ($invoices !== [])
        <section class="rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900" data-test="invoices">
            <flux:heading size="lg" level="2">{{ __('Invoices') }}</flux:heading>
            <flux:text class="mt-1">{{ __('GoProperty\'s invoices for your subscription, the newest first. Units are counted on each billing day.') }}</flux:text>

            <div class="mt-4 divide-y divide-zinc-100 dark:divide-zinc-800">
                @foreach ($invoices as $invoice)
                    <details class="py-3" data-invoice="{{ $invoice->number }}">
                        <summary class="flex cursor-pointer flex-wrap items-center justify-between gap-2">
                            <span>
                                <span class="font-medium text-zinc-900 dark:text-white">{{ $invoice->number }}</span>
                                <span class="block text-sm text-zinc-500 dark:text-zinc-400">{{ __(':plan, :period', ['plan' => $invoice->planName, 'period' => $invoice->period]) }}</span>
                            </span>
                            <span class="text-end">
                                <span class="font-semibold text-zinc-900 dark:text-white" data-test="invoice-total">{{ $invoice->total->format() }}</span>
                                <flux:badge size="sm" class="ms-2" data-test="invoice-status">{{ $invoice->statusLabel }}</flux:badge>
                                @if ($invoice->status === 'open' && $invoice->dueDate)
                                    <span class="block text-sm text-zinc-500 dark:text-zinc-400">{{ __('Due :date', ['date' => $invoice->dueDate->format('j F Y')]) }}</span>
                                @endif
                                @if ($invoice->status === 'open' && $canPay)
                                    <flux:button size="sm" variant="primary" class="mt-2" wire:click="pay('{{ $invoice->number }}')" data-test="pay-{{ $invoice->number }}">{{ __('Pay :amount', ['amount' => $invoice->total->format()]) }}</flux:button>
                                @endif
                            </span>
                        </summary>
                        <dl class="mt-3 space-y-1 text-sm" data-test="invoice-lines">
                            <div class="flex justify-between gap-4"><dt class="text-zinc-600 dark:text-zinc-300">{{ __('Units counted') }}</dt><dd>{{ number_format($invoice->units) }}</dd></div>
                            @foreach ($invoice->lines as $line)
                                <div class="flex justify-between gap-4"><dt class="text-zinc-600 dark:text-zinc-300">{{ $line->label }}</dt><dd>{{ $line->amount->format() }}</dd></div>
                            @endforeach
                            <div class="flex justify-between gap-4 border-t border-zinc-100 pt-1 dark:border-zinc-800"><dt class="text-zinc-600 dark:text-zinc-300">{{ $invoice->subtotalLabel }}</dt><dd>{{ $invoice->subtotal->format() }}</dd></div>
                            <div class="flex justify-between gap-4"><dt class="text-zinc-600 dark:text-zinc-300">{{ $invoice->vatLabel }}</dt><dd data-test="invoice-vat">{{ $invoice->vat->format() }}</dd></div>
                            <div class="flex justify-between gap-4 font-semibold"><dt>{{ __('Total') }}</dt><dd>{{ $invoice->total->format() }}</dd></div>
                        </dl>
                    </details>

                    @if ($invoice->transfer !== null && ($invoice->status === 'open' || $invoice->transfer->status === 'confirmed'))
                        <div class="pb-3 text-sm" data-test="transfer-status-{{ $invoice->number }}">
                            <flux:badge size="sm" :color="match ($invoice->transfer->status) { 'pending' => 'amber', 'confirmed' => 'green', default => 'red' }">{{ $invoice->transfer->statusLabel }}</flux:badge>
                            <span class="ms-1 text-zinc-500 dark:text-zinc-400">{{ __(':amount sent :date', ['amount' => $invoice->transfer->amount->format(), 'date' => $invoice->transfer->transferredOn?->format('j F Y')]) }}</span>
                            @if ($invoice->status === 'open' && $invoice->transfer->status === 'rejected' && $invoice->transfer->rejectionReason)
                                <span class="mt-1 block text-zinc-700 dark:text-zinc-300" data-test="transfer-rejection">{{ __('GoProperty\'s reason: :reason', ['reason' => $invoice->transfer->rejectionReason]) }}</span>
                            @endif
                        </div>
                    @endif

                    @if ($invoice->status === 'open' && $transferAccount !== null)
                        <div class="mb-3 rounded-lg bg-zinc-50 p-4 text-sm dark:bg-zinc-800" data-test="transfer-{{ $invoice->number }}" wire:key="transfer-{{ $invoice->number }}">
                            <div class="font-medium text-zinc-900 dark:text-white">{{ __('Or pay by bank transfer') }}</div>
                            <dl class="mt-2 grid grid-cols-1 gap-1 sm:grid-cols-2">
                                <div><dt class="inline text-zinc-500 dark:text-zinc-400">{{ __('Bank') }}:</dt> <dd class="inline font-medium" data-test="transfer-bank">{{ $transferAccount->bank }}</dd></div>
                                <div><dt class="inline text-zinc-500 dark:text-zinc-400">{{ __('Account number') }}:</dt> <dd class="inline font-medium" data-test="transfer-account-number">{{ $transferAccount->accountNumber }}</dd></div>
                                <div><dt class="inline text-zinc-500 dark:text-zinc-400">{{ __('Account name') }}:</dt> <dd class="inline font-medium">{{ $transferAccount->accountName }}</dd></div>
                                <div><dt class="inline text-zinc-500 dark:text-zinc-400">{{ __('Narration') }}:</dt> <dd class="inline font-medium" data-test="transfer-narration">{{ $invoice->number }}</dd></div>
                            </dl>
                            <flux:text class="mt-2">{{ __('Send exactly :total with the invoice number as the narration, then send us the proof. The invoice is marked paid once GoProperty sees the money in its account.', ['total' => $invoice->total->format()]) }}</flux:text>

                            @if ($canSendProof && $transferInvoice === $invoice->number)
                                <form wire:submit="sendTransfer" class="mt-4 space-y-3" data-test="transfer-form">
                                    @error('invoice')
                                        <flux:callout variant="danger" icon="x-circle" :heading="$message" data-test="transfer-refused" />
                                    @enderror
                                    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                                        <flux:input wire:model="amount" :label="__('Amount sent (₦)')" inputmode="decimal" required />
                                        <flux:input wire:model="transferredOn" type="date" :label="__('Day of the transfer')" required />
                                        <flux:input wire:model="senderName" :label="__('Name on the account it came from')" required />
                                        <flux:input wire:model="senderBank" :label="__('Bank it came from (optional)')" />
                                        <flux:input wire:model="narration" :label="__('Narration you used (optional)')" />
                                        <flux:input wire:model="file" type="file" accept="image/jpeg,image/png,image/webp,application/pdf" :label="__('Proof: screenshot, photo or PDF, up to 10 MB')" required />
                                    </div>
                                    <div class="flex flex-wrap gap-2">
                                        <flux:button type="submit" variant="primary" size="sm" data-test="send-transfer">{{ __('Send proof') }}</flux:button>
                                        <flux:button type="button" size="sm" variant="ghost" wire:click="cancelTransfer">{{ __('Cancel') }}</flux:button>
                                    </div>
                                </form>
                            @elseif ($canSendProof && ($invoice->transfer === null || ! $invoice->transfer->isPending()))
                                <flux:button size="sm" class="mt-3" wire:click="startTransfer('{{ $invoice->number }}')" data-test="start-transfer-{{ $invoice->number }}">{{ __('Send proof of transfer') }}</flux:button>
                            @endif
                        </div>
                    @endif
                @endforeach
            </div>
        </section>
    @endif
</x-page>
