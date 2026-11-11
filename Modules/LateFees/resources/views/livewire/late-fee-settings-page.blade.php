{{-- Settings → Late fees (Modules\LateFees\Livewire\LateFeeSettingsPage, PL-02). --}}
<x-page :heading="__('Settings')" :description="__('Your organization\'s details, how tenants pay you, and how your documents look.')">
    <x-workspace.settings-nav current="workspace.settings.payments.late-fees" />

    @if ($status)
        <flux:callout variant="success" icon="check-circle" :heading="$status" data-test="status" />
    @endif

    <section class="rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900">
        <flux:heading size="lg" level="2">{{ __('Late fees on rent') }}</flux:heading>
        <flux:text class="mt-1">{{ __('When a rent bill is still unpaid some days after its due date, the tenant gets one late fee: a percentage of what is still unpaid, as its own bill, due the same day. Each rent bill is charged once. The fee goes to the landlord like rent, less your commission. Utility bills, other charges and imported balances never get a fee. To waive a fee, void its bill.') }}</flux:text>
        <flux:callout variant="warning" icon="scale" class="mt-4" :heading="__('Charge late fees only if your tenancy agreements provide for them.')" />

        <form wire:submit="save" class="mt-5 flex flex-col gap-4 sm:max-w-sm">
            <flux:switch wire:model="enabled" :label="__('Charge late fees')" :disabled="! $canEdit" data-test="late-fees-enabled" />
            <flux:input wire:model="rate" :label="__('Percentage of what is unpaid')" suffix="%" inputmode="decimal" :disabled="! $canEdit" data-test="late-fee-rate" />
            <flux:input wire:model="graceDays" :label="__('Days after the due date')" :description="__('A bill due on the 1st with 7 days gets its fee on the 8th.')" inputmode="numeric" :disabled="! $canEdit" data-test="late-fee-grace" />
            <flux:input wire:model="cap" :label="__('Most a fee can be (₦, optional)')" :placeholder="__('No cap')" inputmode="numeric" :disabled="! $canEdit" data-test="late-fee-cap" />
            @if ($canEdit)
                <div><flux:button type="submit" variant="primary" data-test="save-late-fees">{{ __('Save') }}</flux:button></div>
            @else
                <flux:text>{{ __('You can see these settings but not change them.') }}</flux:text>
            @endif
        </form>
    </section>
</x-page>
