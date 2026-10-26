<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
{{-- Sign-in and app pages are never indexed (PLATFORM_CONSOLE.md § 9.4). --}}
<meta name="robots" content="noindex, nofollow" />

@php
    $headTheme = app(\App\Theme\ThemeContext::class)->current();
    $themeName = $headTheme->name();
@endphp
<title>
    {{ filled($title ?? null) ? $title.' - '.$themeName : $themeName }}
</title>

<link rel="icon" href="{{ app(\App\Theme\BrandAssets::class)->faviconUrl($headTheme) }}">
<link rel="apple-touch-icon" href="{{ route('brand.asset', 'apple-touch-icon') }}">

@vite(['resources/css/app.css', 'resources/js/app.js'])
<x-theme-styles />
@fluxAppearance
