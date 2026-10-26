{{-- Agency or owner workspace (/o/{organization}); filled from P01 on. --}}
<x-layouts::app :title="$organization->name">
    <x-page :heading="$organization->name" :description="__('Your properties, leases, bills and payments will appear here.')">
        <x-empty-state icon="building-office-2" :heading="__('Nothing to do yet')" :description="__('When payments arrive, bills fall due or tenants raise tickets, they show up here first.')" />
    </x-page>
</x-layouts::app>
