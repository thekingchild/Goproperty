{{-- Tenant portal (/portal), across every agency the tenant rents from. --}}
<x-layouts::app :title="__('My rentals')">
    <x-page :heading="__('My rentals')" :description="__('Your leases, bills, payments and maintenance requests will appear here.')">
        <x-empty-state icon="home-modern" :heading="__('No rentals yet')" :description="__('When an agency adds you as a tenant, your lease and bills appear here.')" />
    </x-page>
</x-layouts::app>
