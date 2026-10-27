{{--
    A bill (Modules\Billing\Livewire\BillShow). Void shows only when the
    bill and the member's permissions allow it; the server checks both again.
    The invoice links (web page, PDF) show when BillPolicy::viewInvoice allows.
--}}
<x-page :heading="$bill->number" :description="$row->tenantName.($row->unitLabel !== null ? ' · '.$row->unitLabel.', '.$row->buildingName : '')">
    <x-slot:actions>
        <flux:button icon="arrow-left" :href="route('workspace.bills.index', $organization)" wire:navigate>{{ __('All bills') }}</flux:button>
        @if ($canViewInvoice)
            <flux:button icon="document-text" :href="route('workspace.bills.invoice', [$organization, $bill])" target="_blank" data-test="bill-invoice">{{ __('Invoice') }}</flux:button>
            <flux:button icon="arrow-down-tray" :href="route('workspace.bills.invoice.pdf', [$organization, $bill])" data-test="bill-invoice-pdf">{{ __('Invoice (PDF)') }}</flux:button>
        @endif
    </x-slot:actions>

    @if ($notice)
        <flux:callout variant="success" icon="check-circle" :heading="$notice" role="status" data-test="bill-notice" />
    @endif

    @error('bill')
        <flux:callout variant="warning" icon="exclamation-triangle" :heading="$message" role="alert" data-test="bill-warning" />
    @enderror

    <div class="grid gap-6 lg:grid-cols-3">
        <section class="rounded-xl border border-zinc-200 bg-white p-5 lg:col-span-2 dark:border-zinc-700 dark:bg-zinc-900" data-test="bill-details">
            <div class="flex flex-wrap items-center gap-2">
                <flux:heading size="lg" level="2">{{ __($bill->type->label()) }}</flux:heading>
                <flux:badge size="sm" :color="$bill->status->color()" data-test="bill-status">{{ __($bill->status->label()) }}</flux:badge>
            </div>

            <dl class="mt-4 grid gap-4 sm:grid-cols-2">
                <div>
                    <dt class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Tenant') }}</dt>
                    <dd class="font-medium text-zinc-900 dark:text-white"><a href="{{ route('workspace.tenants.show', [$organization, $bill->tenant_profile_id]) }}" wire:navigate class="hover:underline">{{ $row->tenantName }}</a></dd>
                </div>
                @if ($row->unitLabel !== null)
                    <div>
                        <dt class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Unit') }}</dt>
                        <dd class="font-medium text-zinc-900 dark:text-white">{{ $row->unitLabel }} · {{ $row->buildingName }}</dd>
                    </div>
                @endif
                @if ($bill->period_start && $bill->period_end)
                    <div>
                        <dt class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Period') }}</dt>
                        <dd class="text-zinc-900 dark:text-white" data-test="bill-period">{{ __(':start to :end', ['start' => $bill->period_start->format('d/m/Y'), 'end' => $bill->period_end->format('d/m/Y')]) }}</dd>
                    </div>
                @endif
                <div>
                    <dt class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Due') }}</dt>
                    <dd class="text-zinc-900 dark:text-white" data-test="bill-due">{{ $bill->due_date->format('d/m/Y') }}</dd>
                </div>
                <div>
                    <dt class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Payment reference') }}</dt>
                    <dd class="font-mono text-zinc-900 dark:text-white" data-test="payment-reference">{{ $bill->payment_reference }}</dd>
                </div>
                <div>
                    <dt class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Issued') }}</dt>
                    <dd class="text-zinc-900 dark:text-white">{{ $bill->issued_at->timezone('Africa/Lagos')->format('d/m/Y') }}</dd>
                </div>
                @if ($bill->lease_id !== null)
                    <div>
                        <dt class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Lease') }}</dt>
                        <dd class="text-zinc-900 dark:text-white"><a href="{{ route('workspace.leases.show', [$organization, $bill->lease_id]) }}" wire:navigate class="underline" data-test="bill-lease">{{ __('Open the lease') }}</a></dd>
                    </div>
                @endif
                @if ($bill->voided_at)
                    <div class="sm:col-span-2">
                        <dt class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Voided') }}</dt>
                        <dd class="text-zinc-900 dark:text-white" data-test="bill-void-reason">{{ $bill->voided_at->timezone('Africa/Lagos')->format('d/m/Y') }} · {{ $bill->void_reason }}</dd>
                    </div>
                @endif
            </dl>

            <ul class="mt-6 divide-y divide-zinc-100 border-t border-zinc-100 dark:divide-zinc-800 dark:border-zinc-800" data-test="bill-items">
                @foreach ($items as $item)
                    <li class="flex flex-col gap-1 py-3 sm:flex-row sm:justify-between" data-test="bill-item">
                        <span class="flex flex-col">
                            <span class="text-zinc-900 dark:text-white">{{ $item->label }}</span>
                            @if ($item->note)
                                <span class="text-sm text-zinc-500 dark:text-zinc-400">{{ $item->note }}</span>
                            @endif
                        </span>
                        <span class="text-zinc-900 dark:text-white">{{ $item->amount_kobo->format() }}</span>
                    </li>
                @endforeach
            </ul>
        </section>

        <section class="rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900" data-test="bill-money">
            <flux:heading size="lg" level="2">{{ __('Amount') }}</flux:heading>
            <dl class="mt-4 flex flex-col gap-3">
                <div class="flex justify-between">
                    <dt class="text-zinc-500 dark:text-zinc-400">{{ __('Total') }}</dt>
                    <dd class="text-zinc-900 dark:text-white" data-test="bill-amount">{{ $bill->amount_kobo->format() }}</dd>
                </div>
                <div class="flex justify-between">
                    <dt class="text-zinc-500 dark:text-zinc-400">{{ __('Paid') }}</dt>
                    <dd class="text-zinc-900 dark:text-white" data-test="bill-paid">{{ $bill->paid_kobo->format() }}</dd>
                </div>
                <div class="flex justify-between font-medium">
                    <dt class="text-zinc-900 dark:text-white">{{ __('Outstanding') }}</dt>
                    <dd class="text-zinc-900 dark:text-white" data-test="bill-outstanding">{{ $bill->outstanding()->format() }}</dd>
                </div>
            </dl>
        </section>

        @if ($canVoid)
            <section class="rounded-xl border border-zinc-200 bg-white p-5 lg:col-span-3 dark:border-zinc-700 dark:bg-zinc-900" data-test="bill-void">
                <flux:heading size="lg" level="2">{{ __('Void this bill') }}</flux:heading>
                <flux:text class="mt-1">{{ __('A void bill stays on record, asks for nothing and accepts no payment. It cannot be undone.') }}</flux:text>
                <form wire:submit="void" class="mt-4 flex flex-col gap-3 sm:max-w-xl" novalidate>
                    <flux:input wire:model="reason" :label="__('Reason')" maxlength="500" required data-test="void-reason" />
                    <div>
                        <flux:button type="submit" variant="danger" wire:confirm="{{ __('Void this bill? It cannot be undone.') }}" data-test="void-bill">{{ __('Void bill') }}</flux:button>
                    </div>
                </form>
            </section>
        @endif
    </div>
</x-page>
