{{--
    Settings → Data export (App\Livewire\Settings\DataExportPage, P09-02):
    the organization's export, one tenant's export, and the exports of the
    last 7 days with their download links (15 minutes each).
--}}
<x-page :heading="__('Settings')" :description="__('Your organization\'s details, how tenants pay you, and how your documents look.')">
    <x-workspace.settings-nav current="workspace.settings.data-export" />

    @if ($notice)
        <flux:callout variant="success" icon="check-circle" :heading="$notice" data-test="notice" />
    @endif

    <section class="rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900" data-test="organization-export">
        <flux:heading size="lg" level="2">{{ __('Export your organization\'s data') }}</flux:heading>
        <flux:text class="mt-1">{{ __('One archive with every record your organization keeps here (buildings, units, owners, tenants, leases, bills, payments, receipts, tickets, groups, expenses and more) as JSON files, and the files you and your tenants uploaded or were issued. ID and bank account numbers show only their last four characters.') }}</flux:text>
        <div class="mt-4">
            <flux:button variant="primary" wire:click="exportOrganization" data-test="export-organization">{{ __('Export everything') }}</flux:button>
        </div>
        <flux:error name="organization" />
    </section>

    <section class="mt-6 rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900" data-test="tenant-export">
        <flux:heading size="lg" level="2">{{ __('Export one tenant\'s data') }}</flux:heading>
        <flux:text class="mt-1">{{ __('When a tenant asks for a copy of their personal data, you answer as the organization that keeps it. The archive holds only that tenant\'s records with you, and their files.') }}</flux:text>

        <div class="mt-4 grid gap-4 sm:max-w-xl">
            <flux:input wire:model.live.debounce.300ms="tenantSearch" :label="__('Find the tenant')" :placeholder="__('Name, phone or email')" data-test="tenant-search" />

            @if ($matches !== [] && $tenantId === null)
                <ul class="divide-y divide-zinc-200 rounded-lg border border-zinc-200 dark:divide-zinc-700 dark:border-zinc-700" data-test="tenant-matches">
                    @foreach ($matches as $id => $label)
                        <li><button type="button" class="w-full px-3 py-2 text-start text-sm hover:bg-zinc-50 dark:hover:bg-zinc-800" wire:click="chooseTenant({{ $id }})">{{ $label }}</button></li>
                    @endforeach
                </ul>
            @endif

            @if ($chosen)
                <flux:text data-test="tenant-chosen">{{ __('Tenant: :tenant', ['tenant' => $chosen]) }}</flux:text>
            @endif
            <flux:error name="tenantId" />

            <flux:input wire:model="reason" :label="__('Who asked, and when')" :placeholder="__('Asked by email on 3 November')" maxlength="300" data-test="tenant-reason" />

            <div>
                <flux:button variant="primary" wire:click="exportTenant" data-test="export-tenant">{{ __('Export this tenant\'s data') }}</flux:button>
            </div>
        </div>
    </section>

    <section class="mt-6" data-test="exports">
        <flux:heading size="lg" level="2">{{ __('Recent exports') }}</flux:heading>
        <flux:text class="mt-1">{{ __('Kept for :days days. Each download link works for 15 minutes; open this page again for a new one. Keep the archive safe: it holds personal data.', ['days' => $days]) }}</flux:text>

        @if ($exports->isEmpty())
            <flux:text class="mt-3" data-test="no-exports">{{ __('No exports yet.') }}</flux:text>
        @else
            <ul class="mt-3 divide-y divide-zinc-200 rounded-xl border border-zinc-200 bg-white dark:divide-zinc-700 dark:border-zinc-700 dark:bg-zinc-900" wire:poll.15s>
                @foreach ($exports as $export)
                    <li class="flex flex-wrap items-center justify-between gap-3 p-4" data-test="export-{{ $export->id }}">
                        <div>
                            <div class="font-medium">{{ $export->scope === \App\Privacy\DataRequestScope::Tenant ? __('One tenant (#:id)', ['id' => $export->tenant_profile_id]) : __('Whole organization') }}</div>
                            <div class="text-sm text-zinc-500 dark:text-zinc-400">{{ $export->created_at?->timezone('Africa/Lagos')->format('j M Y H:i') }} · {{ $export->status->label() }}</div>
                        </div>
                        @isset($links[$export->id])
                            <flux:button size="sm" :href="$links[$export->id]" icon="arrow-down-tray" data-test="download-{{ $export->id }}">{{ __('Download') }}</flux:button>
                        @endisset
                    </li>
                @endforeach
            </ul>
        @endif
    </section>
</x-page>
