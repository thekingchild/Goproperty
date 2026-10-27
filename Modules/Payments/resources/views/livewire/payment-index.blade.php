{{--
    Payments (/o/{organization}/payments; Modules\Payments\Livewire\PaymentIndex).
    "To confirm" is the confirmation queue. Rows stack into cards on phones
    (UX_DESIGN.md § 6); every page and action checks its policy.
--}}
<x-page :heading="__('Payments')" :description="__('Money tenants paid to :organization, recorded and confirmed here.', ['organization' => $organization->name])">
    <x-slot:actions>
        @if ($canRecord)
            <flux:button variant="primary" icon="plus" :href="route('workspace.payments.create', $organization)" wire:navigate data-test="record-payment">{{ __('Record a payment') }}</flux:button>
        @endif
        <x-list-export list="payments" />
    </x-slot:actions>

    <div class="flex flex-col gap-3">
        <div class="flex flex-wrap items-center gap-2" role="group" aria-label="{{ __('Which payments') }}">
            <flux:button size="sm" :variant="$status === 'pending_confirmation' ? 'primary' : 'ghost'" wire:click="showQueue" data-test="show-queue">
                {{ __('To confirm') }} <flux:badge size="sm" color="amber" class="ms-1" data-test="waiting-count">{{ $waiting }}</flux:badge>
            </flux:button>
            <flux:button size="sm" :variant="$status === '' ? 'primary' : 'ghost'" wire:click="showQueue(false)" data-test="show-all">{{ __('All payments') }}</flux:button>
        </div>

        <div class="w-full sm:max-w-sm">
            <flux:input wire:model.live.debounce.400ms="search" icon="magnifying-glass" type="search" :placeholder="__('Search by tenant, payer or reference')" :aria-label="__('Search payments')" maxlength="100" data-test="payment-search" />
        </div>

        <div class="grid gap-3 sm:grid-cols-3">
            <flux:select wire:model.live="status" :aria-label="__('Status')" size="sm" data-test="status-filter">
                <option value="">{{ __('All statuses') }}</option>
                @foreach ($statuses as $option)
                    <option value="{{ $option->value }}">{{ __($option->label()) }}</option>
                @endforeach
            </flux:select>
            <flux:select wire:model.live="method" :aria-label="__('Method')" size="sm" data-test="method-filter">
                <option value="">{{ __('All methods') }}</option>
                @foreach ($methods as $option)
                    <option value="{{ $option->value }}">{{ __($option->label()) }}</option>
                @endforeach
            </flux:select>
            <flux:select wire:model.live="source" :aria-label="__('Source')" size="sm" data-test="source-filter">
                <option value="">{{ __('All sources') }}</option>
                @foreach ($sources as $option)
                    <option value="{{ $option->value }}">{{ __($option->label()) }}</option>
                @endforeach
            </flux:select>
        </div>

        @if ($tenant !== '' || $bill !== '')
            <div class="flex flex-wrap items-center gap-2">
                <flux:text>{{ $bill !== '' ? __('Showing the payments made for one bill.') : __('Showing the payments of one tenant.') }}</flux:text>
                <flux:button size="sm" variant="ghost" wire:click="clearFilters" data-test="show-all-payments">{{ __('Show every payment') }}</flux:button>
            </div>
        @endif
    </div>

    @if ($payments->isEmpty())
        @if ($filtered)
            <x-empty-state icon="magnifying-glass" :heading="$status === 'pending_confirmation' && $search === '' && $method === '' && $source === '' ? __('Nothing to confirm') : __('No payment matches')" :description="__('Check the spelling, or clear the filters to see every payment.')">
                <x-slot:action>
                    <flux:button wire:click="clearFilters" data-test="clear-filters">{{ __('Clear filters') }}</flux:button>
                </x-slot:action>
            </x-empty-state>
        @else
            <x-empty-state icon="credit-card" :heading="__('No payments yet')" :description="__('Record a bank transfer, cash or POS payment when a tenant pays.')" />
        @endif
    @else
        <ul class="divide-y divide-zinc-100 rounded-xl border border-zinc-200 bg-white dark:divide-zinc-800 dark:border-zinc-700 dark:bg-zinc-900" data-test="payments-list">
            @foreach ($rows as $row)
                @php($payment = $row->payment)
                <li class="flex flex-col gap-2 p-4 sm:flex-row sm:items-center sm:justify-between" wire:key="payment-{{ $payment->id }}" data-payment="{{ $payment->id }}">
                    <div class="flex min-w-0 flex-col">
                        <a href="{{ route('workspace.payments.show', [$organization, $payment->id]) }}" wire:navigate class="truncate font-medium text-zinc-900 hover:underline dark:text-white">{{ $row->tenantName }}</a>
                        <span class="text-sm text-zinc-500 dark:text-zinc-400">{{ __($payment->method->label()) }} · {{ __('paid :date', ['date' => $payment->paid_at->format('d/m/Y')]) }}@if ($payment->reference) · {{ $payment->reference }}@endif</span>
                    </div>

                    <div class="flex flex-wrap items-center gap-2">
                        <span class="font-medium text-zinc-900 dark:text-white" data-test="amount-{{ $payment->id }}">{{ $payment->amount_kobo->format() }}</span>
                        <flux:badge size="sm" :color="$payment->status->color()" data-test="status-{{ $payment->id }}">{{ __($payment->status->label()) }}</flux:badge>
                    </div>
                </li>
            @endforeach
        </ul>

        <flux:pagination :paginator="$payments" />
    @endif
</x-page>
