{{-- Agency or owner workspace (/o/{organization}); filled from P01 on. The setup checklist shows until it is done. --}}
<x-layouts::app :title="$organization->name">
    <x-page :heading="$organization->name" :description="__('Your properties, leases, bills and payments will appear here.')">
        @if (session('status'))
            <flux:callout variant="success" icon="check-circle" :heading="session('status')" />
        @endif

        @if ($checklist !== null)
            <x-onboarding.checklist :items="$checklist" :organization="$organization" />
        @endif

        <x-empty-state icon="building-office-2" :heading="__('Nothing to do yet')" :description="__('When payments arrive, bills fall due or tenants raise tickets, they show up here first.')" />
    </x-page>
</x-layouts::app>
