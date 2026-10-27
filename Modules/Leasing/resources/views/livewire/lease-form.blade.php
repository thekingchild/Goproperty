{{--
    Draft a lease, change a draft, or draft a renewal
    (Modules\Leasing\Livewire\LeaseForm). Amounts are typed in naira; the
    server keeps them as kobo and checks everything again.
--}}
<x-page :heading="$renewing ? __('Renew :contract', ['contract' => $lease->reference()]) : ($lease ? __('Edit draft lease') : __('New lease'))" :description="$renewing ? __('The renewal is a new lease for the same tenant and unit. It starts after the current one ends.') : __('A draft holds a vacant unit for the tenant for :days days. Activate it once the lease is signed.', ['days' => $reservationDays])">
    <x-slot:actions>
        <flux:button icon="arrow-left" :href="$lease ? route('workspace.leases.show', [$organization, $lease->id]) : route('workspace.leases.index', $organization)" wire:navigate>{{ $lease ? __('Back to lease') : __('All leases') }}</flux:button>
    </x-slot:actions>

    @error('lease')
        <flux:callout variant="warning" icon="exclamation-triangle" :heading="$message" role="alert" data-test="lease-warning" />
    @enderror

    <form wire:submit="save" class="flex flex-col gap-6" novalidate>
        <section class="rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900">
            <flux:heading size="lg" level="2">{{ __('Tenant and unit') }}</flux:heading>

            @if ($lease)
                <dl class="mt-4 grid gap-4 sm:grid-cols-2">
                    <div>
                        <dt class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Tenant') }}</dt>
                        <dd class="font-medium text-zinc-900 dark:text-white" data-test="fixed-tenant">{{ $fixedTenant ?? __('Unknown tenant') }}</dd>
                    </div>
                    <div>
                        <dt class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Unit') }}</dt>
                        <dd class="font-medium text-zinc-900 dark:text-white" data-test="fixed-unit">{{ $fixedUnit ?? __('Archived unit') }}</dd>
                    </div>
                </dl>
            @else
                <div class="mt-4 grid gap-5 sm:grid-cols-2">
                    <div class="sm:col-span-2">
                        <flux:select wire:model="tenantId" :label="__('Tenant')" required data-test="lease-tenant">
                            <option value="">{{ __('Choose a tenant') }}</option>
                            @foreach ($tenants as $optionId => $optionName)
                                <option value="{{ $optionId }}">{{ $optionName }}</option>
                            @endforeach
                        </flux:select>
                        <flux:text class="mt-1 text-sm">
                            {{ __('Not on the list?') }}
                            <a href="{{ route('workspace.tenants.create', $organization) }}" wire:navigate class="underline">{{ __('Add the tenant first') }}</a>
                        </flux:text>
                    </div>

                    <flux:select wire:model.live="propertyId" :label="__('Building')" required data-test="lease-building">
                        <option value="">{{ __('Choose a building') }}</option>
                        @foreach ($buildings as $optionId => $optionName)
                            <option value="{{ $optionId }}">{{ $optionName }}</option>
                        @endforeach
                    </flux:select>

                    <flux:select wire:model.live="unitId" :label="__('Unit')" required data-test="lease-unit">
                        <option value="">{{ __('Choose a unit') }}</option>
                        @foreach ($units as $option)
                            <option value="{{ $option->id }}">{{ $option->label }} ({{ __($option->statusLabel) }})</option>
                        @endforeach
                    </flux:select>
                </div>
            @endif
        </section>

        <section class="rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900">
            <flux:heading size="lg" level="2">{{ __('Dates and rent') }}</flux:heading>

            <div class="mt-4 grid gap-5 sm:grid-cols-2">
                <flux:input wire:model="startDate" type="date" :label="__('Starts on')" required data-test="lease-start" />
                <flux:input wire:model="endDate" type="date" :label="__('Ends on')" :description="__('The last day of the lease.')" required data-test="lease-end" />

                <flux:select wire:model="billingCycle" :label="__('Billing cycle')" data-test="lease-cycle">
                    @foreach ($cycles as $option)
                        <option value="{{ $option->value }}">{{ __($option->label()) }}</option>
                    @endforeach
                </flux:select>
                <flux:input wire:model="rent" inputmode="decimal" :label="__('Rent per period (₦)')" :description="__('What each period of the cycle costs: a yearly lease, the rent for a year.')" placeholder="1,500,000" maxlength="20" required data-test="lease-rent" />

                @if ($renewing)
                    <div>
                        <flux:text class="text-sm font-medium">{{ __('Caution deposit') }}</flux:text>
                        <flux:text class="mt-1" data-test="carried-deposit">{{ __(':amount carried over from the current lease.', ['amount' => ($carriedDeposit ?? \App\Support\Money::zero())->format()]) }}</flux:text>
                    </div>
                @elseif ($lease && $lease->renewal_of_lease_id !== null)
                    <div>
                        <flux:text class="text-sm font-medium">{{ __('Caution deposit') }}</flux:text>
                        <flux:text class="mt-1">{{ __(':amount carried over from the lease it renews.', ['amount' => $lease->deposit_kobo->format()]) }}</flux:text>
                    </div>
                @else
                    <flux:input wire:model="deposit" inputmode="decimal" :label="__('Caution deposit (₦)')" :description="__('Recorded when the lease is activated, and refunded or kept at move-out. GoProperty never holds it.')" placeholder="150,000" maxlength="20" :badge="__('Optional')" data-test="lease-deposit" />
                @endif

                <flux:input wire:model="dueDayOffset" type="number" inputmode="numeric" min="0" max="27" :label="__('Due days after the period starts')" :description="__('0: each bill is due on the first day of its period.')" data-test="lease-due-offset" />
                <flux:input wire:model="paidUntil" type="date" :label="__('Rent already paid until')" :description="__('If the tenant paid ahead, billing starts with the first period after this day.')" :badge="__('Optional')" data-test="lease-paid-until" />

                <div class="sm:col-span-2">
                    <flux:checkbox wire:model="autoRenew" :label="__('Renews automatically')" :description="__('Kept active past its end date and flagged for renewal, instead of expiring.')" data-test="lease-auto-renew" />
                </div>

                <div class="sm:col-span-2">
                    <flux:textarea wire:model="notes" :label="__('Notes')" rows="3" maxlength="2000" :badge="__('Optional')" />
                </div>
            </div>
        </section>

        <div class="flex gap-2">
            <flux:button type="submit" variant="primary" data-test="save-lease">{{ $renewing ? __('Draft renewal') : ($lease ? __('Save draft') : __('Draft lease')) }}</flux:button>
        </div>
    </form>
</x-page>
