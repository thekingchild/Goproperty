{{--
    Page shell for the signup wizard's Livewire steps (ONBOARDING_COMPLIANCE.md
    § 2). The component draws the split layout itself (x-onboarding.frame),
    so the step list updates with each step. Not the sign-in pages' strict
    policy: Livewire needs its own script here. Never prints custom code.
--}}
@php
    $site = app(\App\Marketing\PublicSite::class);
    $theme = $site->theme();
    $assets = app(\App\Theme\BrandAssets::class);
@endphp
<!DOCTYPE html>
<html lang="en-NG">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{{ $site->title($title ?? 'Set up') }}</title>
<meta name="robots" content="noindex, nofollow">
<link rel="icon" href="{{ $assets->faviconUrl($theme) }}">
@vite(['resources/css/auth.css'])
<x-theme-styles />
</head>
<body>
<a class="skip-link" href="#main">Skip to content</a>
{{ $slot }}
</body>
</html>
