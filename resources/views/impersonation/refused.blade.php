{{-- A support hand-off link that could not be used (PLATFORM_CONSOLE.md § 6). Seen by staff only. --}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ __('Support link not valid') }}</title>
    <x-theme-styles />
</head>
<body style="margin:0;min-height:100vh;display:grid;place-items:center;font:16px/1.5 system-ui,sans-serif;background:var(--surface,#f8fafc);color:var(--ink,#111827);">
    <main style="max-width:28rem;padding:2rem;text-align:center;">
        <h1 style="font-size:1.25rem;margin:0 0 .5rem;">{{ __('This support link cannot be used') }}</h1>
        <p style="margin:0;">{{ $message }}</p>
    </main>
</body>
</html>
