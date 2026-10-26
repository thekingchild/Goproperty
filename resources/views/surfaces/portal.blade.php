{{-- Tenant portal shell (/portal), across every agency the tenant rents from. --}}
<x-layouts::app :title="__('My rentals')">
    <div class="flex flex-col gap-2">
        <flux:heading size="xl" level="1">{{ __('My rentals') }}</flux:heading>
        <flux:text>{{ __('Your leases, bills, payments and maintenance requests will appear here.') }}</flux:text>
    </div>
</x-layouts::app>
