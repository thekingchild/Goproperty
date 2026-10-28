{{--
    Record a meter reading, or correct or remove the latest one
    (Modules\Billing\Livewire\UtilityReadingForm).
--}}
<x-page :heading="$reading ? __('Correct a reading') : __('Record a reading')" :description="$reading ? __(':utility, :unit · :building', ['utility' => $reading->setting->utilityType->name, 'unit' => $row->unitLabel, 'building' => $row->buildingName]) : __('Read the meter, then enter the number it shows.')">
    <x-slot:actions>
        <flux:button icon="arrow-left" :href="route('workspace.utilities.readings.index', $organization)" wire:navigate>{{ __('Meter readings') }}</flux:button>
    </x-slot:actions>

    <form wire:submit="save" class="flex flex-col gap-6" novalidate>
        <section class="rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900">
            <div class="grid gap-5 sm:grid-cols-2">
                @if (! $reading)
                    <flux:select wire:model.live="building" :label="__('Building')" data-test="reading-building">
                        <option value="">{{ __('Choose a building') }}</option>
                        @foreach ($buildings as $buildingId => $buildingName)
                            <option value="{{ $buildingId }}">{{ $buildingName }}</option>
                        @endforeach
                    </flux:select>
                    <div>
                        <flux:select wire:model.live="meter" :label="__('Unit and utility')" data-test="reading-meter">
                            <option value="">{{ __('Choose the meter') }}</option>
                            @foreach ($meters as $meterId => $meterLabel)
                                <option value="{{ $meterId }}">{{ $meterLabel }}</option>
                            @endforeach
                        </flux:select>
                        @if ($building !== '' && $meters === [])
                            <flux:text class="mt-1">{{ __('No unit of this building pays a metered utility.') }}</flux:text>
                        @endif
                    </div>
                @endif

                @if ($setting)
                    <div class="sm:col-span-2" data-test="previous-reading">
                        <flux:text>
                            @if ($previous)
                                {{ __('Last reading: :value on :date.', ['value' => $previous->current_value->format(), 'date' => $previous->reading_date->format('d/m/Y')]) }}
                            @else
                                {{ __('No reading yet: the meter started at :value on :date.', ['value' => $startsAt->format(), 'date' => $setting->effective_from->format('d/m/Y')]) }}
                            @endif
                        </flux:text>
                    </div>
                @endif

                <flux:input wire:model="readingDate" type="date" :label="__('Date read')" required data-test="reading-date" />
                <flux:input wire:model="value" :label="__('Reading')" :description="$setting?->utilityType->unit_of_measure ? __('In :unit, as the meter shows it.', ['unit' => __($setting->utilityType->unit_of_measure->label())]) : null" inputmode="decimal" placeholder="12345.6" maxlength="20" required data-test="reading-value" />

                <div class="sm:col-span-2">
                    <flux:checkbox wire:model.live="meterReplaced" :label="__('Meter replaced')" :description="__('A new meter starts again. Record a last reading of the old meter first, so its use is billed.')" data-test="reading-meter-replaced" />
                </div>
                @if ($meterReplaced)
                    <flux:input wire:model="newMeterStart" :label="__('The new meter\'s first value')" inputmode="decimal" placeholder="0" maxlength="20" data-test="reading-new-meter-start" />
                @endif

                <div class="sm:col-span-2">
                    <flux:input wire:model="note" :label="__('Note')" maxlength="255" data-test="reading-note" />
                </div>

                @if (! $reading)
                    <div class="sm:col-span-2">
                        <flux:input type="file" wire:model="photo" :label="__('Photo of the meter (optional)')" accept="image/jpeg,image/png,image/webp" data-test="reading-photo" />
                    </div>
                @endif
            </div>
        </section>

        <div class="flex gap-3">
            <flux:button type="submit" variant="primary" data-test="save-reading">{{ $reading ? __('Save the correction') : __('Record the reading') }}</flux:button>
        </div>
    </form>

    @if ($canRemove)
        <section class="rounded-xl border border-red-200 bg-white p-5 dark:border-red-900 dark:bg-zinc-900">
            <flux:heading size="lg" level="2">{{ __('Remove this reading') }}</flux:heading>
            <flux:text class="mt-1">{{ __('The reading before it becomes the last one. The removed reading is kept, with its photo, and shows who removed it and why.') }}</flux:text>
            <form wire:submit="remove" class="mt-4 flex flex-col gap-3 sm:flex-row sm:items-end" novalidate>
                <div class="grow">
                    <flux:input wire:model="removalReason" :label="__('Why')" maxlength="255" required data-test="removal-reason" />
                </div>
                <flux:button type="submit" variant="danger" data-test="remove-reading">{{ __('Remove') }}</flux:button>
            </form>
        </section>
    @endif
</x-page>
