{{--
    A lease (Modules\Leasing\Livewire\LeaseShow). Actions show only when
    the lease's state and the member's permissions allow them; the server
    checks both again.
--}}
<x-page :heading="$row->tenantName.' · '.$row->unitLabel" :description="__(':contract, :building', ['contract' => $lease->reference(), 'building' => $row->buildingName])">
    <x-slot:actions>
        <flux:button icon="arrow-left" :href="route('workspace.leases.index', $organization)" wire:navigate>{{ __('All leases') }}</flux:button>
        @if ($canEdit)
            <flux:button icon="pencil-square" :href="route('workspace.leases.edit', [$organization, $lease->id])" wire:navigate data-test="edit-lease">{{ __('Edit draft') }}</flux:button>
        @endif
        @if ($canActivate)
            <flux:button variant="primary" wire:click="activate" wire:confirm="{{ __('Activate this lease? The unit becomes occupied and the lease gets its contract number.') }}" data-test="activate-lease">{{ __('Activate') }}</flux:button>
        @endif
        @if ($lease->contract_number !== null && \Illuminate\Support\Facades\Route::has('workspace.bills.index'))
            {{-- The Billing module's list of this lease's bills (its page checks who may see it). --}}
            <flux:button icon="banknotes" :href="route('workspace.bills.index', [$organization, 'lease' => $lease->id])" wire:navigate data-test="lease-bills">{{ __('Bills') }}</flux:button>
        @endif
        @if ($canRenew)
            <flux:button icon="arrow-path" :href="route('workspace.leases.renew', [$organization, $lease->id])" wire:navigate data-test="renew-lease">{{ __('Renew') }}</flux:button>
        @endif
    </x-slot:actions>

    @if ($notice)
        <flux:callout variant="success" icon="check-circle" :heading="$notice" role="status" data-test="lease-notice" />
    @elseif ($flash)
        <flux:callout variant="success" icon="check-circle" :heading="$flash" role="status" data-test="lease-notice" />
    @endif

    @error('lease')
        <flux:callout variant="warning" icon="exclamation-triangle" :heading="$message" role="alert" data-test="lease-warning" />
    @enderror

    <div class="grid gap-6 lg:grid-cols-3">
        <section class="rounded-xl border border-zinc-200 bg-white p-5 lg:col-span-2 dark:border-zinc-700 dark:bg-zinc-900" data-test="lease-terms">
            <div class="flex flex-wrap items-center gap-2">
                <flux:heading size="lg" level="2">{{ __('Terms') }}</flux:heading>
                <flux:badge size="sm" :color="$lease->status->color()" data-test="lease-status">{{ __($lease->status->label()) }}</flux:badge>
                @if ($lease->ending_soon_flagged_at !== null && $lease->status === \Modules\Leasing\Enums\LeaseStatus::Active)
                    <flux:badge size="sm" color="amber">{{ __('Ending soon') }}</flux:badge>
                @endif
            </div>

            <dl class="mt-4 grid gap-4 sm:grid-cols-2">
                <div>
                    <dt class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Tenant') }}</dt>
                    <dd class="font-medium text-zinc-900 dark:text-white"><a href="{{ route('workspace.tenants.show', [$organization, $lease->tenant_profile_id]) }}" wire:navigate class="hover:underline">{{ $row->tenantName }}</a></dd>
                </div>
                <div>
                    <dt class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Unit') }}</dt>
                    <dd class="font-medium text-zinc-900 dark:text-white">{{ $row->unitLabel }} · {{ $row->buildingName }}</dd>
                </div>
                <div>
                    <dt class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Contract number') }}</dt>
                    <dd class="text-zinc-900 dark:text-white" data-test="contract-number">{{ $lease->contract_number ?? __('Given when the lease is activated') }}</dd>
                </div>
                <div>
                    <dt class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Dates') }}</dt>
                    <dd class="text-zinc-900 dark:text-white">{{ __(':start to :end', ['start' => $lease->start_date->format('d/m/Y'), 'end' => $lease->end_date->format('d/m/Y')]) }}</dd>
                </div>
                <div>
                    <dt class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Rent') }}</dt>
                    <dd class="text-zinc-900 dark:text-white">{{ __(':amount, billed :cycle', ['amount' => $lease->rent_amount_kobo->format(), 'cycle' => mb_strtolower(__($lease->billing_cycle->label()))]) }}</dd>
                </div>
                <div>
                    <dt class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Each bill is due') }}</dt>
                    <dd class="text-zinc-900 dark:text-white">{{ $lease->due_day_offset === 0 ? __('On the first day of its period') : trans_choice(':count day after its period starts|:count days after its period starts', $lease->due_day_offset, ['count' => $lease->due_day_offset]) }}</dd>
                </div>
                @if ($lease->paid_until)
                    <div>
                        <dt class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Paid until') }}</dt>
                        <dd class="text-zinc-900 dark:text-white">{{ $lease->paid_until->format('d/m/Y') }}</dd>
                    </div>
                @endif
                @if ($lease->next_billing_date && in_array($lease->status, [\Modules\Leasing\Enums\LeaseStatus::Active, \Modules\Leasing\Enums\LeaseStatus::Renewed], true))
                    <div>
                        <dt class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Next period to bill') }}</dt>
                        <dd class="text-zinc-900 dark:text-white" data-test="next-billing-date">{{ $lease->next_billing_date->greaterThan($lease->end_date) ? __('None: billed to the end') : __('Starts :date', ['date' => $lease->next_billing_date->format('d/m/Y')]) }}</dd>
                    </div>
                @endif
                @if ($lease->status === \Modules\Leasing\Enums\LeaseStatus::Draft && $lease->reserved_until)
                    <div>
                        <dt class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Unit held until') }}</dt>
                        <dd class="text-zinc-900 dark:text-white" data-test="reserved-until">{{ $lease->reserved_until->format('d/m/Y') }}</dd>
                    </div>
                @endif
                <div>
                    <dt class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('At the end date') }}</dt>
                    <dd class="text-zinc-900 dark:text-white">{{ $lease->auto_renew ? __('Kept active and flagged for renewal') : __('Expires') }}</dd>
                </div>
                @if ($lease->termination_reason)
                    <div class="sm:col-span-2">
                        <dt class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Why it ended') }}</dt>
                        <dd class="text-zinc-900 dark:text-white">{{ $lease->termination_reason }}</dd>
                    </div>
                @endif
                @if ($lease->notes)
                    <div class="sm:col-span-2">
                        <dt class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Notes') }}</dt>
                        <dd class="whitespace-pre-line text-zinc-900 dark:text-white">{{ $lease->notes }}</dd>
                    </div>
                @endif
            </dl>
        </section>

        <section class="rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900" data-test="lease-history">
            <flux:heading size="lg" level="2">{{ __('Renewals') }}</flux:heading>

            @if ($renewalOf === null && $renewals->isEmpty())
                <flux:text class="mt-2">{{ __('This lease renews no other lease, and has not been renewed.') }}</flux:text>
            @endif

            @if ($renewalOf)
                <flux:text class="mt-2" data-test="renewal-of">
                    {{ __('Renews') }}
                    <a href="{{ route('workspace.leases.show', [$organization, $renewalOf->id]) }}" wire:navigate class="underline">{{ $renewalOf->reference() }}</a>
                    ({{ __($renewalOf->status->label()) }})
                </flux:text>
            @endif

            @foreach ($renewals as $renewal)
                <flux:text class="mt-2" data-test="renewed-by">
                    {{ __('Renewed by') }}
                    <a href="{{ route('workspace.leases.show', [$organization, $renewal->id]) }}" wire:navigate class="underline">{{ $renewal->reference() }}</a>
                    ({{ __($renewal->status->label()) }})
                </flux:text>
            @endforeach
        </section>

        <section class="rounded-xl border border-zinc-200 bg-white p-5 lg:col-span-3 dark:border-zinc-700 dark:bg-zinc-900" data-test="lease-deposit">
            <flux:heading size="lg" level="2">{{ __('Caution deposit') }}</flux:heading>
            <flux:text class="mt-1">{{ __('A record of the deposit the landlord holds. GoProperty never holds or moves it.') }}</flux:text>

            @if ($entries->isEmpty())
                <flux:text class="mt-3">{{ $lease->deposit_kobo->isPositive() ? __(':amount, recorded when the lease is activated.', ['amount' => $lease->deposit_kobo->format()]) : __('No deposit.') }}</flux:text>
            @else
                <ul class="mt-3 divide-y divide-zinc-100 dark:divide-zinc-800">
                    @foreach ($entries as $entry)
                        <li class="flex flex-col gap-1 py-2 sm:flex-row sm:justify-between" data-test="deposit-entry">
                            <span class="text-zinc-900 dark:text-white">{{ __($entry->type->label()) }}@if ($entry->note) · {{ $entry->note }}@endif</span>
                            <span class="text-zinc-600 dark:text-zinc-300">{{ $entry->amount_kobo->format() }} · {{ $entry->created_at?->timezone('Africa/Lagos')->format('d/m/Y') }}</span>
                        </li>
                    @endforeach
                </ul>
                <flux:text class="mt-2 font-medium" data-test="deposit-balance">{{ __('Held now: :amount', ['amount' => $depositBalance->format()]) }}</flux:text>
            @endif

            @if ($canSettle)
                <form wire:submit="settleDeposit" class="mt-4 grid gap-4 sm:grid-cols-3" novalidate>
                    <flux:input wire:model="refunded" inputmode="decimal" :label="__('Refunded to the tenant (₦)')" maxlength="20" data-test="deposit-refunded" />
                    <flux:input wire:model="kept" inputmode="decimal" :label="__('Kept (₦)')" maxlength="20" data-test="deposit-kept" />
                    <flux:input wire:model="note" :label="__('Why it is kept')" maxlength="500" data-test="deposit-note" />
                    <div class="sm:col-span-3">
                        <flux:button type="submit" data-test="settle-deposit">{{ __('Record') }}</flux:button>
                    </div>
                </form>
            @endif
        </section>

        @if ($canTerminate)
            <section class="rounded-xl border border-zinc-200 bg-white p-5 lg:col-span-3 dark:border-zinc-700 dark:bg-zinc-900" data-test="lease-end">
                <flux:heading size="lg" level="2">{{ $lease->status === \Modules\Leasing\Enums\LeaseStatus::Draft ? __('Cancel this draft') : __('End this lease early') }}</flux:heading>
                <form wire:submit="terminate" class="mt-4 flex flex-col gap-3 sm:max-w-xl" novalidate>
                    <flux:input wire:model="reason" :label="__('Reason')" maxlength="500" required data-test="terminate-reason" />
                    <div>
                        <flux:button type="submit" variant="danger" wire:confirm="{{ $lease->status === \Modules\Leasing\Enums\LeaseStatus::Draft ? __('Cancel this draft? The unit is released.') : __('End this lease now? It cannot be undone.') }}" data-test="terminate-lease">{{ $lease->status === \Modules\Leasing\Enums\LeaseStatus::Draft ? __('Cancel draft') : __('End lease') }}</flux:button>
                    </div>
                </form>
            </section>
        @endif
    </div>
</x-page>
