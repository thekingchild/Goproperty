{{--
    A tenant's record (Modules\Tenants\Livewire\TenantShow). The ID number
    is shown masked only; the full number never reaches the browser.
--}}
<x-page :heading="$tenant->fullName()" :description="$tenant->trashed() ? __('Archived tenant of :organization.', ['organization' => $organization->name]) : __('Tenant of :organization.', ['organization' => $organization->name])">
    <x-slot:actions>
        <flux:button icon="arrow-left" :href="route('workspace.tenants.index', $tenant->trashed() ? [$organization, 'archived' => 1] : $organization)" wire:navigate>{{ __('All tenants') }}</flux:button>
        @if ($canEdit)
            <flux:button icon="pencil-square" :href="route('workspace.tenants.edit', [$organization, $tenant])" wire:navigate data-test="edit-tenant">{{ __('Edit') }}</flux:button>
        @endif
        @if ($canArchive)
            <flux:button variant="danger" wire:click="archive" wire:confirm="{{ __('Archive :name? The record is kept with its history, and you can restore it later.', ['name' => $tenant->fullName()]) }}" data-test="archive-tenant">{{ __('Archive') }}</flux:button>
        @endif
        @if ($canRestore)
            <flux:button variant="primary" wire:click="restore" data-test="restore-tenant">{{ __('Restore') }}</flux:button>
        @endif
    </x-slot:actions>

    @if ($notice)
        <flux:callout variant="success" icon="check-circle" :heading="$notice" role="status" data-test="tenant-notice" />
    @elseif ($flash)
        <flux:callout variant="success" icon="check-circle" :heading="$flash" role="status" data-test="tenant-notice" />
    @endif

    @if ($tenant->trashed())
        <flux:callout variant="warning" icon="archive-box" :heading="__('This tenant is archived')" :text="__('The record is kept with its history and cannot be changed until it is restored.')" data-test="archived-banner" />
    @endif

    <div class="grid gap-6 lg:grid-cols-3">
        <section class="rounded-xl border border-zinc-200 bg-white p-5 lg:col-span-2 dark:border-zinc-700 dark:bg-zinc-900" data-test="tenant-contact">
            <flux:heading size="lg" level="2">{{ __('Contact') }}</flux:heading>

            <dl class="mt-4 grid gap-4 sm:grid-cols-2">
                <div>
                    <dt class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Phone number') }}</dt>
                    <dd class="font-medium text-zinc-900 dark:text-white"><a href="tel:{{ $tenant->phone }}" class="hover:underline">{{ $tenant->phone }}</a></dd>
                </div>
                <div>
                    <dt class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Email address') }}</dt>
                    <dd class="break-all text-zinc-900 dark:text-white">{{ $tenant->email ?? __('Not given') }}</dd>
                </div>
                <div class="sm:col-span-2">
                    <dt class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Address') }}</dt>
                    <dd class="text-zinc-900 dark:text-white">{{ $tenant->address ?? __('Not given') }}</dd>
                </div>
                <div>
                    <dt class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Occupation') }}</dt>
                    <dd class="text-zinc-900 dark:text-white">{{ $tenant->occupation ?? __('Not given') }}</dd>
                </div>
                <div>
                    <dt class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Portal access') }}</dt>
                    <dd class="text-zinc-900 dark:text-white" data-test="portal-access">{{ $tenant->user_id !== null ? __('Has a portal account') : __('Not invited yet') }}</dd>
                </div>
            </dl>
        </section>

        <section class="rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900" data-test="tenant-kin">
            <flux:heading size="lg" level="2">{{ __('Next of kin') }}</flux:heading>

            @php($kin = $tenant->emergency_contact ?? [])
            @if (array_filter($kin) === [])
                <flux:text class="mt-2">{{ __('No next of kin on file.') }}</flux:text>
            @else
                <dl class="mt-4 grid gap-4">
                    <div>
                        <dt class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Name') }}</dt>
                        <dd class="font-medium text-zinc-900 dark:text-white">{{ $kin['name'] ?? __('Not given') }}</dd>
                    </div>
                    <div>
                        <dt class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Phone number') }}</dt>
                        <dd class="text-zinc-900 dark:text-white">{{ $kin['phone'] ?? __('Not given') }}</dd>
                    </div>
                    <div>
                        <dt class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Relationship') }}</dt>
                        <dd class="text-zinc-900 dark:text-white">{{ $kin['relationship'] ?? __('Not given') }}</dd>
                    </div>
                </dl>
            @endif
        </section>

        <section class="rounded-xl border border-zinc-200 bg-white p-5 lg:col-span-3 dark:border-zinc-700 dark:bg-zinc-900" data-test="tenant-identity">
            <flux:heading size="lg" level="2">{{ __('Identity and KYC') }}</flux:heading>

            <dl class="mt-4 grid gap-4 sm:grid-cols-3">
                <div>
                    <dt class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('KYC status') }}</dt>
                    <dd class="mt-1">
                        <flux:badge size="sm" :color="match ($tenant->kyc_status) { \Modules\Tenants\Enums\KycStatus::Verified => 'green', \Modules\Tenants\Enums\KycStatus::Pending => 'amber', default => 'zinc' }" data-test="kyc-status">{{ __($tenant->kyc_status->label()) }}</flux:badge>
                    </dd>
                </div>
                <div>
                    <dt class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('ID') }}</dt>
                    <dd class="text-zinc-900 dark:text-white" data-test="masked-id">{{ $maskedId ?? __('No ID on file') }}</dd>
                </div>
                @if ($tenant->external_ref)
                    <div>
                        <dt class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Your ID') }}</dt>
                        <dd class="text-zinc-900 dark:text-white">{{ $tenant->external_ref }}</dd>
                    </div>
                @endif
            </dl>
        </section>

        {{-- Filled by the lease and bill tasks (P03-03a, P03-04b). --}}
        <section class="rounded-xl border border-dashed border-zinc-300 bg-white p-5 lg:col-span-3 dark:border-zinc-700 dark:bg-zinc-900" data-test="tenant-leases-bills">
            <flux:heading size="lg" level="2">{{ __('Leases and bills') }}</flux:heading>
            <flux:text class="mt-2">{{ __('This tenant\'s leases, bills and payments will show here once leases are added.') }}</flux:text>
        </section>
    </div>
</x-page>
