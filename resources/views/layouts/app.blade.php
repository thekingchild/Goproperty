<x-layouts::app.sidebar :title="$title ?? null">
    {{-- Room for the bottom navigation on phones. --}}
    <flux:main class="pb-24 lg:pb-8">
        <x-organization-status-notice />
        {{ $slot }}
    </flux:main>
</x-layouts::app.sidebar>
