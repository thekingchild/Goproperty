{{--
    The utilities area's three pages (P03-06a): utility types, the units'
    utilities and meter readings. Each link shows only when its policy lets
    the member see the page.
--}}
@props(['organization', 'current', 'canSeeSettings' => true, 'canSeeReadings' => true])

<nav class="flex flex-wrap gap-2" aria-label="{{ __('Utilities') }}" data-test="utility-tabs">
    <flux:button size="sm" :variant="$current === 'types' ? 'primary' : 'ghost'" :href="route('workspace.utilities.index', $organization)" wire:navigate data-test="tab-types">{{ __('Utility types') }}</flux:button>
    @if ($canSeeSettings)
        <flux:button size="sm" :variant="$current === 'settings' ? 'primary' : 'ghost'" :href="route('workspace.utilities.settings.index', $organization)" wire:navigate data-test="tab-settings">{{ __('Units\' utilities') }}</flux:button>
    @endif
    @if ($canSeeReadings)
        <flux:button size="sm" :variant="$current === 'readings' ? 'primary' : 'ghost'" :href="route('workspace.utilities.readings.index', $organization)" wire:navigate data-test="tab-readings">{{ __('Meter readings') }}</flux:button>
    @endif
</nav>
