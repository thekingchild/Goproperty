{{-- Product name and mark from the platform theme (never hard-coded; AGENT.md § 4). --}}
@props([
    'sidebar' => false,
])

@php
    $logoTheme = app(\App\Theme\ThemeContext::class)->current();
    $themeName = $logoTheme->name();
    // An uploaded square icon replaces the generated mark.
    $icon = app(\App\Theme\BrandAssets::class)->url($logoTheme, 'icon');
@endphp

@if($sidebar)
    <flux:sidebar.brand :name="$themeName" {{ $attributes }}>
        <x-slot name="logo" class="flex aspect-square size-8 items-center justify-center">
            @if ($icon !== null)<img src="{{ $icon }}" alt="" class="size-8 rounded-md object-contain">@else<x-brand-mark />@endif
        </x-slot>
    </flux:sidebar.brand>
@else
    <flux:brand :name="$themeName" {{ $attributes }}>
        <x-slot name="logo" class="flex aspect-square size-8 items-center justify-center">
            @if ($icon !== null)<img src="{{ $icon }}" alt="" class="size-8 rounded-md object-contain">@else<x-brand-mark />@endif
        </x-slot>
    </flux:brand>
@endif
