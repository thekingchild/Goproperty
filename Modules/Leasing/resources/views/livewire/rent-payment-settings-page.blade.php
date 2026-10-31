{{-- Settings → Rent payments (Modules\Leasing\Livewire\RentPaymentSettingsPage, P03-17). --}}
<x-page :heading="__('Settings')" :description="__('Your organization\'s details, how tenants pay you, and how your documents look.')">
    <x-workspace.settings-nav current="workspace.settings.payments.rent" />

    @if ($status)
        <flux:callout variant="success" icon="check-circle" :heading="$status" data-test="status" />
    @endif

    <section class="rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900">
        <flux:heading size="lg" level="2">{{ __('Part payments of rent') }}</flux:heading>
        <flux:text class="mt-1">{{ __('How many payments a tenant may make on one rent bill. The last allowed payment must clear the bill, and each bill keeps its one due date. A lease can have its own number, set on the lease\'s page. Utility and other bills have no limit.') }}</flux:text>

        <form wire:submit="save" class="mt-5 flex flex-col gap-4 sm:max-w-sm">
            <flux:select wire:model="limit" :label="__('Payments allowed on a rent bill')" :disabled="! $canEdit" data-test="rent-payment-limit">
                <flux:select.option value="">{{ __('No limit') }}</flux:select.option>
                @for ($count = 1; $count <= $max; $count++)
                    <flux:select.option value="{{ $count }}">{{ $count === 1 ? __('1 (paid in full at once)') : $count }}</flux:select.option>
                @endfor
            </flux:select>
            @if ($canEdit)
                <div><flux:button type="submit" variant="primary" data-test="save-rent-payments">{{ __('Save') }}</flux:button></div>
            @else
                <flux:text>{{ __('You can see this setting but not change it.') }}</flux:text>
            @endif
        </form>
    </section>
</x-page>
