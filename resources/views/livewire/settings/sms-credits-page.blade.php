{{--
    Settings → SMS credits (App\Livewire\Settings\SmsCreditsPage, P05-03;
    BILLING_PAYMENTS.md § 1.2): balance, this month's allowance, purchased
    credits, bundles to buy (owners), the latest purchases and the ledger.
    Ledger rows stack into cards on phones (UX_DESIGN.md § 6).
--}}
@php
    use App\Sms\Credits\SmsCreditLedger;

    $month = \Carbon\CarbonImmutable::createFromFormat('!Y-m', $summary->month, 'Africa/Lagos')->format('F Y');
    $statusColor = fn (string $status): string => match ($status) {
        'paid' => 'green',
        'held' => 'amber',
        'failed' => 'zinc',
        default => 'blue',
    };
@endphp

<x-page :heading="__('Settings')" :description="__('Your organization\'s details, how tenants pay you, and how your documents look.')">
    <x-workspace.settings-nav current="workspace.settings.sms-credits" />

    @if ($status)
        <flux:callout :variant="$status[0] === 'success' ? 'success' : 'warning'" :icon="$status[0] === 'success' ? 'check-circle' : 'exclamation-triangle'" :heading="$status[1]" data-test="status" />
    @endif

    @if ($summary->balance === 0)
        <flux:callout variant="danger" icon="exclamation-triangle" :heading="__('No SMS credits left: SMS reminders have stopped.')" data-test="credits-empty">
            <flux:callout.text>{{ __('Email and in-app reminders carry on. Next month\'s allowance arrives on :date.', ['date' => $resetsOn->format('j F Y')]) }}</flux:callout.text>
        </flux:callout>
    @elseif ($summary->low())
        <flux:callout variant="warning" icon="exclamation-triangle" :heading="__('SMS credits are running low.')" data-test="credits-low" />
    @endif

    <section class="rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900" data-test="sms-credits">
        <flux:heading size="lg" level="2">{{ __('SMS credits') }}</flux:heading>
        <flux:text class="mt-1">{{ __('Each SMS reminder to a tenant uses one credit. Your plan includes :allowance credits a month; unused ones expire at the end of the month. Credits you buy never expire. Allowance credits are used first.', ['allowance' => number_format($summary->monthlyAllowance)]) }}</flux:text>

        <dl class="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-3">
            <div class="rounded-lg bg-zinc-50 p-4 dark:bg-zinc-800">
                <dt class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Balance') }}</dt>
                <dd class="mt-1 text-2xl font-semibold text-zinc-900 dark:text-white" data-test="balance">{{ number_format($summary->balance) }}</dd>
            </div>
            <div class="rounded-lg bg-zinc-50 p-4 dark:bg-zinc-800">
                <dt class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Allowance for :month', ['month' => $month]) }}</dt>
                <dd class="mt-1 text-2xl font-semibold text-zinc-900 dark:text-white" data-test="allowance-left">{{ __(':left of :granted left', ['left' => number_format($summary->allowanceLeft), 'granted' => number_format($summary->allowanceGranted)]) }}</dd>
                <dd class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Resets on :date', ['date' => $resetsOn->format('j F Y')]) }}</dd>
            </div>
            <div class="rounded-lg bg-zinc-50 p-4 dark:bg-zinc-800">
                <dt class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Purchased') }}</dt>
                <dd class="mt-1 text-2xl font-semibold text-zinc-900 dark:text-white" data-test="purchased">{{ number_format($summary->purchased) }}</dd>
                <dd class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Never expire') }}</dd>
            </div>
        </dl>
    </section>

    <section class="rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900" data-test="bundles">
        <flux:heading size="lg" level="2">{{ __('Buy SMS credits') }}</flux:heading>

        @error('buy')
            <flux:callout class="mt-3" variant="danger" icon="x-circle" :heading="$message" data-test="buy-refused" />
        @enderror

        @if (! $available || $bundles === [])
            <flux:text class="mt-2" data-test="buying-unavailable">{{ __('Buying SMS credits online is not available yet. Contact support to add credits.') }}</flux:text>
        @else
            <flux:text class="mt-1">{{ $canBuy ? __('Pay by card or bank through Paystack. The credits are added as soon as the payment is confirmed.') : __('Only an owner of the organization can buy credits.') }}</flux:text>
            <ul class="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-2">
                @foreach ($bundles as $bundle)
                    <li class="flex items-center justify-between gap-3 rounded-lg border border-zinc-200 p-4 dark:border-zinc-700" wire:key="bundle-{{ $bundle->credits }}" data-test="bundle-{{ $bundle->credits }}">
                        <div class="flex flex-col">
                            <span class="font-medium text-zinc-900 dark:text-white">{{ __(':credits credits', ['credits' => number_format($bundle->credits)]) }}</span>
                            <span class="text-sm text-zinc-500 dark:text-zinc-400">{{ __(':total, VAT included', ['total' => $bundle->total->format()]) }}</span>
                        </div>
                        @if ($canBuy)
                            <flux:button size="sm" variant="primary" wire:click="buy({{ $bundle->credits }})" data-test="buy-{{ $bundle->credits }}">{{ __('Buy') }}</flux:button>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif

        @if ($purchases !== [])
            <flux:heading size="md" level="3" class="mt-6">{{ __('Latest purchases') }}</flux:heading>
            <ul class="mt-2 divide-y divide-zinc-100 dark:divide-zinc-800" data-test="purchases">
                @foreach ($purchases as $purchase)
                    <li class="flex flex-wrap items-center justify-between gap-2 py-3" wire:key="purchase-{{ $purchase->invoiceNumber }}">
                        <div class="flex flex-col">
                            <span class="font-medium text-zinc-900 dark:text-white">{{ __(':credits credits', ['credits' => number_format($purchase->credits)]) }} · {{ $purchase->total->format() }}</span>
                            <span class="text-sm text-zinc-500 dark:text-zinc-400">{{ $purchase->invoiceNumber }} · {{ $purchase->startedAt->setTimezone('Africa/Lagos')->format('j M Y, H:i') }}</span>
                        </div>
                        <flux:badge size="sm" :color="$statusColor($purchase->status)">{{ __($purchase->statusLabel) }}</flux:badge>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>

    <section aria-labelledby="ledger-heading" class="flex flex-col gap-3">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <flux:heading size="lg" level="2" id="ledger-heading">{{ __('Credit history') }}</flux:heading>
            <x-list-export list="sms_credit_entries" size="sm" />
        </div>

        @if ($entries->isEmpty())
            <flux:text data-test="no-entries">{{ __('Nothing yet. Your monthly allowance appears here once it is granted.') }}</flux:text>
        @else
            <ul class="divide-y divide-zinc-100 rounded-xl border border-zinc-200 bg-white dark:divide-zinc-800 dark:border-zinc-700 dark:bg-zinc-900" data-test="ledger">
                @foreach ($entries as $entry)
                    <li class="flex flex-col gap-1 p-4 sm:flex-row sm:items-center sm:justify-between" wire:key="entry-{{ $entry->id }}" data-test="entry-{{ $entry->id }}">
                        <div class="flex min-w-0 flex-col">
                            <span class="font-medium text-zinc-900 dark:text-white">{{ __($entry->reason->label()) }}</span>
                            <span class="truncate text-sm text-zinc-500 dark:text-zinc-400">{{ $entry->created_at?->setTimezone('Africa/Lagos')->format('j M Y, H:i') }}@if ($details = SmsCreditLedger::details($entry)) · {{ $details }}@endif</span>
                        </div>
                        <div class="flex items-baseline gap-4 sm:flex-col sm:items-end sm:gap-0">
                            <span class="font-semibold {{ $entry->credits < 0 ? 'text-zinc-700 dark:text-zinc-300' : 'text-green-700 dark:text-green-400' }}">{{ ($entry->credits > 0 ? '+' : '').number_format($entry->credits) }}</span>
                            <span class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Balance :balance', ['balance' => number_format($entry->balance_after)]) }}</span>
                        </div>
                    </li>
                @endforeach
            </ul>
            <flux:pagination :paginator="$entries" />
        @endif
    </section>
</x-page>
