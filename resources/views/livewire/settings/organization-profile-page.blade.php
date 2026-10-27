{{-- Settings → Profile (App\Livewire\Settings\OrganizationProfilePage). --}}
<x-page :heading="__('Settings')" :description="__('Your organization\'s details, how tenants pay you, and how your documents look.')">
    <x-workspace.settings-nav current="workspace.settings.profile" />

    @if ($flash)
        <flux:callout variant="success" icon="check-circle" :heading="$flash" data-test="status" />
    @elseif ($status)
        <flux:callout variant="success" icon="check-circle" :heading="$status" data-test="status" />
    @endif

    <section class="rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900">
        <flux:heading size="lg" level="2">{{ __('Organization profile') }}</flux:heading>
        <flux:text class="mt-1">{{ __('The company name appears on every invoice and receipt.') }}</flux:text>

        <form wire:submit="save" class="mt-5 grid gap-5 sm:grid-cols-2">
            <div class="sm:col-span-2">
                <flux:input wire:model="name" :label="__('Company name (as registered)')" maxlength="120" :disabled="! $canEdit || $locked" required />
                @if ($locked)
                    <flux:text class="mt-1 text-sm">{{ __('Checked by compliance for your Transaction Tier. Contact support to change it.') }}</flux:text>
                @endif
            </div>

            <div class="sm:col-span-2">
                <flux:input.group :label="__('Web address')" :description="__('Lower-case letters, digits and hyphens. If you change it, the old address keeps working.')">
                    <flux:input.group.prefix>{{ $host }}/o/</flux:input.group.prefix>
                    <flux:input wire:model="slug" :maxlength="$slugMax" autocapitalize="none" spellcheck="false" :disabled="! $canEdit" required />
                </flux:input.group>
                <flux:error name="slug" />
            </div>

            <div class="sm:col-span-2">
                <flux:textarea wire:model="address" :label="__('Address')" rows="2" maxlength="300" :disabled="! $canEdit" />
            </div>

            <flux:select wire:model="state" :label="__('State')" :disabled="! $canEdit" required>
                <option value="">{{ __('Choose a state') }}</option>
                @foreach ($states as $stateName)
                    <option value="{{ $stateName }}">{{ $stateName }}</option>
                @endforeach
            </flux:select>

            <flux:input wire:model="lga" :label="__('LGA')" maxlength="64" :disabled="! $canEdit" />

            <div>
                <flux:input wire:model="cacNumber" :label="__('CAC number')" placeholder="RC 1234567" maxlength="20" :disabled="! $canEdit || $locked" />
            </div>

            @if ($canEdit)
                <div class="sm:col-span-2">
                    <flux:button type="submit" variant="primary">{{ __('Save profile') }}</flux:button>
                </div>
            @else
                <flux:text class="sm:col-span-2">{{ __('You can see these details but not change them.') }}</flux:text>
            @endif
        </form>
    </section>
</x-page>
