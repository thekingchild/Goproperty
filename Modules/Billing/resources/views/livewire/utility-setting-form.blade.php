{{--
    Add a utility to units, or change one unit's utility
    (Modules\Billing\Livewire\UtilitySettingForm).
--}}
<x-page :heading="$setting ? __(':utility for :unit', ['utility' => $setting->utilityType->name, 'unit' => $row->unitLabel]) : __('Add a utility to units')" :description="$setting ? $row->buildingName : __('Choose the building, the utility and the units that pay it.')">
    <x-slot:actions>
        <flux:button icon="arrow-left" :href="route('workspace.utilities.settings.index', $organization)" wire:navigate>{{ __('Units\' utilities') }}</flux:button>
    </x-slot:actions>

    <form wire:submit="save" class="flex flex-col gap-6" novalidate>
        @if (! $setting)
            <section class="rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900">
                <div class="grid gap-5 sm:grid-cols-2">
                    <flux:select wire:model.live="building" :label="__('Building')" data-test="setting-building">
                        <option value="">{{ __('Choose a building') }}</option>
                        @foreach ($buildings as $buildingId => $buildingName)
                            <option value="{{ $buildingId }}">{{ $buildingName }}</option>
                        @endforeach
                    </flux:select>
                    <flux:select wire:model.live="utility" :label="__('Utility')" data-test="setting-utility">
                        <option value="">{{ __('Choose a utility') }}</option>
                        @foreach ($utilities as $option)
                            <option value="{{ $option->id }}">{{ $option->name }} · {{ $option->describeAmount() }}</option>
                        @endforeach
                    </flux:select>
                    <flux:error name="building" />
                    <flux:error name="utilityType" />
                </div>

                @if ($unitOptions !== [])
                    <flux:checkbox.group wire:model="units" :label="__('Units')" class="mt-5" data-test="setting-units">
                        @foreach ($unitOptions as $unitId => $unitOption)
                            <flux:checkbox :value="(string) $unitId" :label="$unitOption->label" />
                        @endforeach
                    </flux:checkbox.group>
                @elseif ($building !== '')
                    <flux:text class="mt-5">{{ __('This building has no units yet.') }}</flux:text>
                @endif
                <flux:error name="units" />
            </section>
        @endif

        <section class="rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900">
            <flux:heading size="lg" level="2">{{ __('Amount and cycle') }}</flux:heading>
            <flux:text class="mt-1">{{ $type ? __('Leave empty to use the utility\'s default: :amount, :cycle.', ['amount' => $type->describeAmount(), 'cycle' => __($type->default_cycle->label())]) : __('Leave empty to use the utility\'s default.') }}</flux:text>

            <div class="mt-4 grid gap-5 sm:grid-cols-2">
                <flux:input wire:model="amount" :label="$type?->isMetered() ? __('Rate for this unit (₦ per unit of measure)') : __('Amount for this unit each cycle (₦)')" inputmode="decimal" :placeholder="$type ? \Modules\Billing\Utilities\UtilityInput::nairaInput($type->default_amount_kobo) : ''" maxlength="20" data-test="setting-amount" />
                <flux:select wire:model="cycle" :label="__('Billing cycle')" data-test="setting-cycle">
                    <option value="">{{ __('The utility\'s default') }}</option>
                    @foreach ($cycles as $option)
                        <option value="{{ $option->value }}">{{ __($option->label()) }}</option>
                    @endforeach
                </flux:select>
                <flux:input wire:model="effectiveFrom" type="date" :label="__('Applies from')" required data-test="setting-effective-from" />
                @if ($type?->isMetered())
                    <div>
                        <flux:input wire:model="startingReading" :label="__('Meter reading on that day')" inputmode="decimal" placeholder="0" maxlength="20" :disabled="$hasReadings" data-test="setting-starting-reading" />
                        @if ($hasReadings)
                            <flux:text class="mt-1">{{ __('The meter has readings: correct the latest one instead.') }}</flux:text>
                        @endif
                    </div>
                @endif
                @if ($setting)
                    <div class="sm:col-span-2">
                        <flux:switch wire:model="active" :label="__('This unit pays this utility')" data-test="setting-active" />
                    </div>
                @endif
            </div>
        </section>

        <div class="flex gap-3">
            <flux:button type="submit" variant="primary" data-test="save-utility-setting">{{ $setting ? __('Save') : __('Add to units') }}</flux:button>
        </div>
    </form>
</x-page>
