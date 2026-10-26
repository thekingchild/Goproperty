{{-- Owner portal (/owner), across the agencies that manage your properties. --}}
<x-layouts::app :title="__('My properties')">
    <x-page :heading="__('My properties')" :description="__('Statements for the properties agencies manage for you will appear here.')">
        <x-empty-state icon="key" :heading="__('No properties yet')" :description="__('When an agency invites you as an owner, your statements appear here.')" />
    </x-page>
</x-layouts::app>
