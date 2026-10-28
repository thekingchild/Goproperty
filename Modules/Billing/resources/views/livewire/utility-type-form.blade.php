{{--
    Add or edit a utility type (Modules\Billing\Livewire\UtilityTypeForm).
--}}
<x-page :heading="$type ? $type->name : __('Add utility')" :description="__('A utility is charged flat each cycle, or metered: the meter\'s consumption times a rate.')">
    <x-slot:actions>
        <flux:button icon="arrow-left" :href="route('workspace.utilities.index', $organization)" wire:navigate>{{ __('All utilities') }}</flux:button>
    </x-slot:actions>

    <form wire:submit="save" class="flex flex-col gap-6" novalidate>
        <section class="rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900">
            <div class="grid gap-5 sm:grid-cols-2">
                <flux:input wire:model="name" :label="__('Name')" :placeholder="__('Security levy')" maxlength="80" required data-test="utility-name" />
                <flux:input wire:model="code" :label="__('Code')" :description="__('Short, for exports and imports: capital letters, digits and dashes.')" placeholder="SECURITY" maxlength="20" required data-test="utility-code" />

                <div class="sm:col-span-2">
                    <flux:radio.group wire:model.live="chargingMode" :label="__('Charging')" :disabled="$chargingLocked" data-test="utility-charging">
                        @foreach ($chargingModes as $mode)
                            <flux:radio :value="$mode->value" :label="__($mode->label())" />
                        @endforeach
                    </flux:radio.group>
                    @if ($chargingLocked)
                        <flux:text class="mt-1" data-test="charging-locked">{{ __('Units use this utility, so how it charges stays as it is.') }}</flux:text>
                    @endif
                    <flux:error name="chargingMode" />
                </div>

                @if ($chargingMode === \Modules\Billing\Enums\ChargingMode::Metered->value)
                    <flux:select wire:model="unitOfMeasure" :label="__('The meter counts')" :disabled="$chargingLocked" data-test="utility-unit">
                        <option value="">{{ __('Choose') }}</option>
                        @foreach ($meterUnits as $unit)
                            <option value="{{ $unit->value }}">{{ __($unit->optionLabel()) }}</option>
                        @endforeach
                    </flux:select>
                @endif

                <flux:input wire:model="amount" :label="$chargingMode === \Modules\Billing\Enums\ChargingMode::Metered->value ? __('Default rate (₦ per unit of measure)') : __('Default amount each cycle (₦)')" inputmode="decimal" placeholder="25,000" maxlength="20" required data-test="utility-amount" />

                <flux:select wire:model="cycle" :label="__('Default billing cycle')" data-test="utility-cycle">
                    @foreach ($cycles as $option)
                        <option value="{{ $option->value }}">{{ __($option->label()) }}</option>
                    @endforeach
                </flux:select>
            </div>
        </section>

        <div class="flex gap-3">
            <flux:button type="submit" variant="primary" data-test="save-utility-type">{{ $type ? __('Save') : __('Add utility') }}</flux:button>
        </div>
    </form>
</x-page>
