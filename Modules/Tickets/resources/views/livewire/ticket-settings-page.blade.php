{{-- Settings → Maintenance tickets (Modules\Tickets\Livewire\TicketSettingsPage). --}}
<x-page :heading="__('Settings')" :description="__('Your organization\'s details, how tenants pay you, and how your documents look.')">
    <x-workspace.settings-nav current="workspace.settings.operations.tickets" />

    @if ($status)
        <flux:callout variant="success" icon="check-circle" :heading="$status" data-test="status" />
    @endif

    <section class="rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900">
        <flux:heading size="lg" level="2">{{ __('Maintenance tickets') }}</flux:heading>
        <flux:text class="mt-1">{{ __('When a ticket is marked resolved, the tenant can confirm it is fixed or reopen it. If they do neither, it closes itself.') }}</flux:text>

        <form wire:submit="save" class="mt-5 grid max-w-xl gap-5">
            <flux:input wire:model="reopenDays" type="number" min="1" :max="$maxReopenDays" :label="__('Days a tenant can reopen a ticket after it is resolved')" :description="__('From 1 to :max days. Your staff can reopen a ticket at any time.', ['max' => $maxReopenDays])" :disabled="! $canEdit" required data-test="reopen-days" />
            <flux:input wire:model="autoCloseDays" type="number" min="1" :max="$maxAutoCloseDays" :label="__('Days before a resolved ticket closes itself')" :description="__('From 1 to :max days, counted from the resolution, when the tenant has not answered.', ['max' => $maxAutoCloseDays])" :disabled="! $canEdit" required data-test="auto-close-days" />
            @if ($canEdit)
                <div>
                    <flux:button type="submit" variant="primary" data-test="save-settings">{{ __('Save') }}</flux:button>
                </div>
            @else
                <flux:text>{{ __('You can see these settings but not change them.') }}</flux:text>
            @endif
        </form>
    </section>
</x-page>
