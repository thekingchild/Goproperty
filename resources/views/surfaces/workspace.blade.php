{{-- Agency or owner workspace shell (/o/{organization}); filled from P01 on. --}}
<x-layouts::app :title="$organization->name">
    <div class="flex flex-col gap-2">
        <flux:heading size="xl" level="1">{{ $organization->name }}</flux:heading>
        <flux:text>{{ __('Your properties, leases, bills and payments will appear here.') }}</flux:text>
    </div>
</x-layouts::app>
