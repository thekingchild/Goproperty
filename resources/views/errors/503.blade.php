{{--
    The maintenance page (php artisan down, or Console → Version → Turn on
    maintenance mode for an upgrade; docs/runbooks/upgrade.md). Nothing else
    answers 503 with a page. Self-contained on purpose: during an upgrade the
    files may be half swapped and the database mid-migration, and every
    other route (assets, /brand images) answers 503 too. So no Vite, no
    Livewire, no images: the published theme's name and colours printed
    inline, or the default theme when the settings cannot be read.
--}}
@php
    try {
        $theme = app(\App\Theme\ThemeContext::class)->current();
    } catch (\Throwable) {
        $theme = new \App\Theme\Theme([]);
    }

    $name = $theme->name();
    $retry = isset($exception) && method_exists($exception, 'getHeaders') ? ($exception->getHeaders()['Retry-After'] ?? null) : null;
    $reload = is_numeric($retry) && (int) $retry >= 15 ? min((int) $retry, 600) : 60;
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta name="theme-color" content="{{ $theme->themeColor() }}">
    <meta http-equiv="refresh" content="{{ $reload }}">
    <title>{{ __(':name is being updated', ['name' => $name]) }}</title>
    <style>
        {!! $theme->css() !!}
        *{box-sizing:border-box}
        body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:16px;
            font-family:var(--font-ui),system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;color:var(--ink);
            background:linear-gradient(160deg,var(--hero-deep),var(--hero-deep-2))}
        main{width:100%;max-width:28rem;background:var(--surface);border-radius:var(--gp-radius-lg,16px);padding:32px 28px;
            box-shadow:0 20px 50px rgba(0,0,0,.25);text-align:center}
        svg{width:48px;height:48px;display:block;margin:0 auto 20px}
        h1{font-size:1.375rem;line-height:1.3;margin:0 0 12px;font-weight:700}
        p{margin:0 0 12px;line-height:1.55;color:var(--muted);font-size:.975rem}
        .small{font-size:.85rem;color:var(--muted-2);margin:16px 0 0}
    </style>
</head>
<body>
    <main role="main" data-test="maintenance">
        <x-brand-mark />
        <h1>{{ __('We are updating :name', ['name' => $name]) }}</h1>
        <p>{{ __('This takes a few minutes. Please try again shortly: this page reloads by itself.') }}</p>
        <p class="small">{{ __('If you sent a form just before the update started, open it again once the site is back and check it was saved.') }}</p>
    </main>
</body>
</html>
