{{--
    Add or edit a unit (Modules\Properties\Livewire\UnitForm).
    Amounts are typed in naira; the server keeps them as kobo.
--}}
<x-page :heading="$unit ? __('Edit :label', ['label' => $unit->unit_label]) : __('Add a unit')" :description="$property->name">
    <x-slot:actions>
        <flux:button icon="arrow-left" :href="route('workspace.buildings.show', [$organization, $property])" wire:navigate>{{ __('Back to building') }}</flux:button>
        @if ($canArchive)
            <flux:button variant="danger" wire:click="archive" wire:confirm="{{ __('Archive :label? It stops counting towards your bill, and you can restore it later.', ['label' => $unit->unit_label]) }}" data-test="archive-unit">{{ __('Archive') }}</flux:button>
        @endif
    </x-slot:actions>

    @error('archive')
        <flux:callout variant="warning" icon="exclamation-triangle" :heading="$message" role="alert" data-test="unit-warning" />
    @enderror
    @error('building')
        <flux:callout variant="warning" icon="exclamation-triangle" :heading="$message" role="alert" data-test="unit-warning" />
    @enderror

    <form wire:submit="save" class="flex flex-col gap-6" novalidate>
        @include('properties::livewire.partials.unit-details', ['labelField' => true])

        <div class="flex gap-2">
            <flux:button type="submit" variant="primary" data-test="save-unit">{{ $unit ? __('Save unit') : __('Add unit') }}</flux:button>
        </div>
    </form>
</x-page>
