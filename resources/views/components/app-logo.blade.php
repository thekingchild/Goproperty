{{-- Product name and mark from the platform theme (never hard-coded; AGENT.md § 4). --}}
@props([
    'sidebar' => false,
])

@php($themeName = app(\App\Theme\ThemeContext::class)->current()->name())

@if($sidebar)
    <flux:sidebar.brand :name="$themeName" {{ $attributes }}>
        <x-slot name="logo" class="flex aspect-square size-8 items-center justify-center">
            <x-brand-mark />
        </x-slot>
    </flux:sidebar.brand>
@else
    <flux:brand :name="$themeName" {{ $attributes }}>
        <x-slot name="logo" class="flex aspect-square size-8 items-center justify-center">
            <x-brand-mark />
        </x-slot>
    </flux:brand>
@endif
