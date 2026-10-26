<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />

@php($themeName = app(\App\Theme\ThemeContext::class)->current()->name())
<title>
    {{ filled($title ?? null) ? $title.' - '.$themeName : $themeName }}
</title>

<link rel="icon" href="/favicon.ico" sizes="any">
<link rel="icon" href="/favicon.svg" type="image/svg+xml">
<link rel="apple-touch-icon" href="/apple-touch-icon.png">

@vite(['resources/css/app.css', 'resources/js/app.js'])
<x-theme-styles />
@fluxAppearance
