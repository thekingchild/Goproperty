{{--
    Add several units at once (Modules\Properties\Livewire\UnitBulkForm).
    Nothing is added when one of the labels is already in the building.
--}}
<x-page :heading="__('Add several units')" :description="$property->name">
    <x-slot:actions>
        <flux:button icon="arrow-left" :href="route('workspace.buildings.show', [$organization, $property])" wire:navigate>{{ __('Back to building') }}</flux:button>
    </x-slot:actions>

    @error('labels')
        <flux:callout variant="danger" icon="exclamation-triangle" :heading="$message" role="alert" data-test="bulk-error" />
    @enderror
    @error('building')
        <flux:callout variant="warning" icon="exclamation-triangle" :heading="$message" role="alert" data-test="bulk-error" />
    @enderror
    @if ($errors->has('labels.*'))
        <ul class="flex flex-col gap-1 text-sm text-red-600 dark:text-red-400" data-test="label-errors">
            @foreach ($errors->get('labels.*') as $messages)
                @foreach ($messages as $labelError)
                    <li>{{ $labelError }}</li>
                @endforeach
            @endforeach
        </ul>
    @endif

    <form wire:submit="save" class="flex flex-col gap-6" novalidate>
        <section class="rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900">
            <flux:heading size="lg" level="2">{{ __('Labels') }}</flux:heading>
            <flux:text class="mt-1">{{ __('Write {n} where the number goes: “Flat {n}” gives Flat 1, Flat 2…; “A{n}” from 101 gives A101, A102…') }}</flux:text>

            <div class="mt-4 grid gap-5 sm:grid-cols-3">
                <flux:input wire:model.live.debounce.300ms="pattern" :label="__('Label pattern')" maxlength="55" autocomplete="off" required data-test="pattern" />
                <flux:input wire:model.live.debounce.300ms="start" type="number" inputmode="numeric" min="0" :label="__('First number')" required data-test="start" />
                <flux:input wire:model.live.debounce.300ms="count" type="number" inputmode="numeric" min="1" max="{{ $maxUnits }}" :label="__('Number of units')" :description="__('Up to :max at a time.', ['max' => $maxUnits])" required data-test="count" />
            </div>

            <flux:text class="mt-3 text-sm" data-test="preview">
                @if ($preview)
                    {{ $preview['count'] === 1 ? __('Adds :first.', ['first' => $preview['first']]) : __('Adds :count units: :first to :last.', $preview) }}
                    {{ __('Each unit counts towards your GoProperty bill.') }}
                @else
                    {{ __('Enter a pattern with {n}, a first number and how many units to add.') }}
                @endif
            </flux:text>
        </section>

        @include('properties::livewire.partials.unit-details', ['labelField' => false])

        <div class="flex gap-2">
            <flux:button type="submit" variant="primary" data-test="save-units">{{ __('Add units') }}</flux:button>
        </div>
    </form>
</x-page>
