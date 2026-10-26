{{-- Owner portal shell (/owner), across the agencies that manage your properties. --}}
<x-layouts::app :title="__('My properties')">
    <div class="flex flex-col gap-2">
        <flux:heading size="xl" level="1">{{ __('My properties') }}</flux:heading>
        <flux:text>{{ __('Statements for the properties agencies manage for you will appear here.') }}</flux:text>
    </div>
</x-layouts::app>
