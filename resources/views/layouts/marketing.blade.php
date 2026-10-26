{{--
    Public marketing pages (UX_DESIGN.md § 4, PLATFORM_CONSOLE.md § 9.4–9.5):
    title, description, canonical, robots, Open Graph and JSON-LD from the
    SEO settings; the theme as CSS variables; the owner's custom code, which
    App\Marketing\PublicSite leaves out for signed-in users, the console
    preview and ?safe=1. Only these pages print it.
--}}
@props(['title' => null, 'schema' => false, 'script' => false])
@php
    $site = app(\App\Marketing\PublicSite::class);
    $siteTheme = $site->theme();
    $pageTitle = $site->title($title);
    $description = $site->description();
    $shareImage = $site->shareImage();
    $code = $site->customCode();
    $json = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_THROW_ON_ERROR;
@endphp
<!DOCTYPE html>
<html lang="en-NG">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{{ $pageTitle }}</title>
@if ($description !== null)
<meta name="description" content="{{ $description }}">
@endif
<link rel="canonical" href="{{ $site->canonical() }}">
<meta name="robots" content="{{ $site->robots() }}">
<meta property="og:type" content="website">
<meta property="og:site_name" content="{{ $siteTheme->name() }}">
<meta property="og:locale" content="en_NG">
<meta property="og:url" content="{{ $site->canonical() }}">
<meta property="og:title" content="{{ $pageTitle }}">
@if ($description !== null)
<meta property="og:description" content="{{ $description }}">
@endif
@if ($shareImage !== null)
<meta property="og:image" content="{{ $shareImage }}">
<meta property="og:image:width" content="1200">
<meta property="og:image:height" content="630">
@endif
<meta name="twitter:card" content="summary_large_image">
<link rel="icon" href="{{ $site->favicon() }}">
<link rel="apple-touch-icon" href="{{ asset('apple-touch-icon.png') }}">
@if ($schema)
<script type="application/ld+json">{!! json_encode($site->structuredData(), $json) !!}</script>
@endif
@vite($script ? ['resources/css/marketing.css', 'resources/js/marketing.js'] : ['resources/css/marketing.css'])
<x-theme-styles />
<script>document.documentElement.classList.add('js')</script>
@isset($code['css'])
<style data-custom-css>{!! $code['css'] !!}</style>
@endisset
@isset($code['head'])
{!! $code['head'] !!}
@endisset
</head>
<body {{ $attributes }}>
@isset($code['body_start'])
{!! $code['body_start'] !!}
@endisset
<a class="skip-link" href="#main">Skip to content</a>
{{ $slot }}
@if ($site->isPreview())
<p class="preview-ribbon" role="status" data-test="preview-banner">{{ ($themePreviewDraft ?? false) ? 'Preview of the draft theme. Visitors still see the published one.' : 'There is no draft: this is the published theme.' }}</p>
@endif
@if ($script)
<script type="application/json" data-page>{!! json_encode(['brand' => $siteTheme->name(), 'pricing' => app(\App\Marketing\PlanCatalogue::class)->forScript()], $json) !!}</script>
@endif
@isset($code['body_end'])
{!! $code['body_end'] !!}
@endisset
</body>
</html>
