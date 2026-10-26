<x-layouts::app :title="__('Dashboard')">
    <x-page :heading="__('Dashboard')" :description="__('Choose a workspace, or open your rentals or properties.')">
        <x-empty-state icon="squares-2x2" :heading="__('Pick where to start')" :description="__('Your workspaces and portals are in the menu.')">
            <x-slot:action>
                <flux:button variant="primary" :href="route('portal.home')" wire:navigate>{{ __('My rentals') }}</flux:button>
            </x-slot:action>
        </x-empty-state>
    </x-page>
</x-layouts::app>
