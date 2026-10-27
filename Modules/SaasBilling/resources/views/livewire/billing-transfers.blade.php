{{--
    Billing transfers (/o/{organization}/billing-transfers;
    Modules\SaasBilling\Livewire\BillingTransfers). The bill as it stands,
    the offer form, the offers made and the offers received (the invite
    inbox). Owners only; every action checks its policy again.
--}}
@php
    $date = fn ($value) => $value?->timezone('Africa/Lagos')->format('j M Y');
    $badge = fn (\Modules\SaasBilling\Models\BillingTransferRequest $transfer): string => match (true) {
        $transfer->isOpen() => 'sky',
        $transfer->status === \Modules\SaasBilling\Enums\BillingTransferStatus::Completed => 'green',
        $transfer->status === \Modules\SaasBilling\Enums\BillingTransferStatus::Declined => 'red',
        default => 'zinc',
    };
    $status = fn (\Modules\SaasBilling\Models\BillingTransferRequest $transfer): string => $transfer->status === \Modules\SaasBilling\Enums\BillingTransferStatus::Pending && $transfer->isPastExpiry()
        ? __('Expired')
        : __($transfer->status->label());
@endphp
<x-page :heading="__('Billing transfers')" :description="__('Hand :organization\'s GoProperty bill to another organization, or take over one offered to you.', ['organization' => $organization->name])">
    @if ($notice)
        <flux:callout variant="success" icon="check-circle" :heading="$notice" role="status" data-test="transfers-notice" />
    @endif

    <section class="rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900" aria-labelledby="offer-heading">
        <flux:heading size="lg" level="2" id="offer-heading">{{ __('Hand over your bill') }}</flux:heading>
        <flux:text class="mt-1" data-test="current-bill">
            {{ __('Your bill today: :amount a month before :vat% VAT (:plan plan, :units units).', ['amount' => $bill->amount->format(), 'vat' => $bill->vatPercent, 'plan' => $bill->plan->name, 'units' => $bill->units]) }}
        </flux:text>
        <flux:text class="mt-1">{{ __('The organization you choose must already be on GoProperty. Its owners are emailed and have :days days to accept; nothing changes unless they do. They do not get access to your buildings.', ['days' => $validDays]) }}</flux:text>

        <form wire:submit="offer" class="mt-4 flex flex-col gap-4" novalidate>
            <flux:input wire:model="target" :label="__('Their web address or an owner\'s email')" placeholder="goproperty.ng/o/their-agency" autocomplete="off" maxlength="255" required data-test="offer-target" />
            <div>
                <flux:button type="submit" variant="primary" data-test="offer-button" wire:confirm="{{ __('Offer your GoProperty bill to this organization? Its owners will be emailed.') }}">{{ __('Offer my bill') }}</flux:button>
            </div>
        </form>
    </section>

    <section aria-labelledby="outgoing-heading" class="flex flex-col gap-3">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <flux:heading size="lg" level="2" id="outgoing-heading">{{ __('Offers you made') }}</flux:heading>
            @if ($outgoing->isNotEmpty())
                <x-list-export list="billing_transfers_made" size="sm" />
            @endif
        </div>
        @error('outgoing')<flux:callout variant="danger" icon="x-circle" :heading="$message" role="alert" />@enderror

        @if ($outgoing->isEmpty())
            <flux:text>{{ __('You have not offered your bill to anyone.') }}</flux:text>
        @else
            <ul class="divide-y divide-zinc-100 rounded-xl border border-zinc-200 bg-white dark:divide-zinc-800 dark:border-zinc-700 dark:bg-zinc-900" data-test="outgoing-list">
                @foreach ($outgoing as $transfer)
                    <li class="flex flex-col gap-3 p-4 sm:flex-row sm:items-center sm:justify-between" wire:key="outgoing-{{ $transfer->id }}" data-transfer="{{ $transfer->id }}">
                        <div class="flex min-w-0 flex-col">
                            <span class="truncate font-medium text-zinc-900 dark:text-white">{{ $transfer->toOrganization->name }}</span>
                            <span class="text-sm text-zinc-500 dark:text-zinc-400">
                                {{ __('offered by :name on :date', ['name' => $transfer->initiatedBy?->name ?? __('a former member'), 'date' => $date($transfer->created_at)]) }}
                                @if ($transfer->isOpen())
                                    · {{ __('expires :date', ['date' => $date($transfer->expires_at)]) }}
                                @elseif ($transfer->responded_at)
                                    · {{ __('answered :date', ['date' => $date($transfer->responded_at)]) }}
                                @endif
                                @if ($transfer->monthly_amount_kobo)
                                    · {{ __(':amount a month', ['amount' => $transfer->monthly_amount_kobo->format()]) }}
                                @endif
                            </span>
                        </div>
                        <div class="flex flex-wrap items-center gap-2">
                            <flux:badge size="sm" :color="$badge($transfer)">{{ $status($transfer) }}</flux:badge>
                            @if ($transfer->isOpen())
                                <flux:button size="sm" variant="danger" wire:click="withdraw({{ $transfer->id }})" wire:confirm="{{ __('Withdraw the offer to :name? Its link stops working at once.', ['name' => $transfer->toOrganization->name]) }}" data-test="withdraw-{{ $transfer->id }}">{{ __('Withdraw') }}</flux:button>
                            @endif
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>

    <section aria-labelledby="incoming-heading" class="flex flex-col gap-3">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <flux:heading size="lg" level="2" id="incoming-heading">{{ __('Offers to you') }}</flux:heading>
            @if ($incoming->isNotEmpty())
                <x-list-export list="billing_transfers_received" size="sm" />
            @endif
        </div>

        @if ($incoming->isEmpty())
            <flux:text>{{ __('No organization has offered you its bill.') }}</flux:text>
        @else
            <ul class="divide-y divide-zinc-100 rounded-xl border border-zinc-200 bg-white dark:divide-zinc-800 dark:border-zinc-700 dark:bg-zinc-900" data-test="incoming-list">
                @foreach ($incoming as $transfer)
                    <li class="flex flex-col gap-3 p-4 sm:flex-row sm:items-center sm:justify-between" wire:key="incoming-{{ $transfer->id }}" data-transfer="{{ $transfer->id }}">
                        <div class="flex min-w-0 flex-col">
                            <span class="truncate font-medium text-zinc-900 dark:text-white">{{ $transfer->organization->name }}</span>
                            <span class="text-sm text-zinc-500 dark:text-zinc-400">
                                {{ __('offered by :name on :date', ['name' => $transfer->initiatedBy?->name ?? __('a former member'), 'date' => $date($transfer->created_at)]) }}
                                @if ($transfer->isOpen())
                                    · {{ __('expires :date', ['date' => $date($transfer->expires_at)]) }}
                                @endif
                            </span>
                        </div>
                        <div class="flex flex-wrap items-center gap-2">
                            <flux:badge size="sm" :color="$badge($transfer)">{{ $status($transfer) }}</flux:badge>
                            <flux:button size="sm" :variant="$transfer->isOpen() ? 'primary' : 'ghost'" :href="route('workspace.billing-transfers.show', [$organization, $transfer->id])" wire:navigate data-test="review-{{ $transfer->id }}">
                                {{ $transfer->isOpen() ? __('Review') : __('Details') }}
                            </flux:button>
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>
</x-page>
