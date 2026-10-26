{{--
    Customer sign-in pages (UX_DESIGN.md § 3.1, § 3.3), from the approved
    design docs/design/templates/login.html: a brand panel (the uploaded
    sign-in photo under its overlay, or the gradient and skyline) beside
    the form. Never prints custom code; noindex.

    Slots: $panel (panel text; defaults to site.auth_*), $top (top-right of
    the form column, desktop), $mobileLink (beside the logo on mobile).
    :activity="false" leaves out the floating activity cards.
--}}
@props(['title', 'panel' => null, 'top' => null, 'mobileLink' => null, 'activity' => true])
@php
    // Tells SignInPageHeaders to send this page's security policy.
    request()->attributes->set(\App\Http\Middleware\SignInPageHeaders::ATTRIBUTE, true);
    $site = app(\App\Marketing\PublicSite::class);
    $theme = $site->theme();
    $brand = $theme->name();
    $assets = app(\App\Theme\BrandAssets::class);
    $photo = $assets->url($theme, 'auth');
@endphp
<!DOCTYPE html>
<html lang="en-NG">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{{ $site->title($title) }}</title>
<meta name="robots" content="noindex, nofollow">
<link rel="icon" href="{{ $assets->faviconUrl($theme) }}">
<link rel="apple-touch-icon" href="{{ route('brand.asset', 'apple-touch-icon') }}">
@vite(['resources/css/auth.css', 'resources/js/auth.js'])
<x-theme-styles />
{{ $head ?? '' }}
</head>
<body>
<a class="skip-link" href="#main">Skip to content</a>

<div class="auth-shell">
  <aside @class(['auth-panel', 'has-photo' => $photo !== null]) aria-label="{{ $brand }}" data-panel>
    @if ($photo !== null)
      <img class="bg-photo" src="{{ $photo }}" srcset="{{ $assets->srcset($theme, 'auth') }}" sizes="55vw" alt="">
    @endif
    <div class="bg-overlay" aria-hidden="true"></div>
    <div class="auth-panel__art" aria-hidden="true"><img src="{{ asset('images/skyline.svg') }}" alt=""></div>
    <div class="auth-panel__shade" aria-hidden="true"></div>

    @include('marketing.partials.brand', ['tone' => 'dark'])
    @if ($mobileLink)
      {{ $mobileLink }}
    @endif

    @if ($activity && $theme->get('theme.auth_show_activity') !== false)
      {{-- Decorative; hidden on short screens. --}}
      <div class="auth-float" aria-hidden="true">
        <div class="glass"><span class="glass__icon glass__icon--ok"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="m5 12 5 5L20 7"/></svg></span><div><strong>Payment confirmed</strong><span>Flat 2A · ₦2,400,000</span></div></div>
        <div class="glass"><span class="glass__icon glass__icon--amber"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 3h12v18l-3-2-3 2-3-2-3 2zM9 8h6M9 12h6"/></svg></span><div><strong>Receipt sent</strong><span>RCT-ADR-2026-000318</span></div></div>
        <div class="glass"><span class="glass__icon glass__icon--blue"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 10h18M5 10v9M19 10v9M9 10v9M15 10v9M3 21h18M12 3 3 8h18z"/></svg></span><div><strong>5 statement lines matched</strong><span>GTBank · 24/10/2026</span></div></div>
      </div>
    @endif

    <div class="auth-panel__body">
      @if ($panel)
        {{ $panel }}
      @else
        @php
            // The last three words of the headline take the accent colour, as in the design.
            $words = preg_split('/\s+/', $theme->string('site.auth_headline')) ?: [];
            $lead = implode(' ', array_slice($words, 0, max(0, count($words) - 3)));
            $accent = implode(' ', array_slice($words, -3));
            $benefitIcons = [
                '<path d="M3 10h18M5 10V20M19 10v10M9 10v10M15 10v10M3 20h18M12 3 3 8h18z"/>',
                '<path d="M6 3h12v18l-3-2-3 2-3-2-3 2zM9 8h6M9 12h6M9 16h3"/>',
                '<path d="M4 20V10M10 20V4M16 20v-7M22 20H2"/>',
            ];
            $trust = array_values(array_filter(array_map('trim', explode('·', $theme->string('site.auth_trustline')))));
        @endphp
        <p class="auth-panel__title">{{ $lead }} <span>{{ $accent }}</span></p>
        <ul class="benefits">
          @foreach ($theme->list('site.auth_benefits') as $index => $benefit)
            <li><span class="benefits__icon" aria-hidden="true"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">{!! $benefitIcons[$index % 3] !!}</svg></span>{{ $benefit }}</li>
          @endforeach
        </ul>
        @if ($trust !== [])
          <p class="trustline">
            @foreach ($trust as $part)
              @if (! $loop->first)<span aria-hidden="true">·</span>@endif
              @if ($loop->first)
                <span><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/></svg>{{ $part }}</span>
              @else
                <span>{{ $part }}</span>
              @endif
            @endforeach
          </p>
        @endif
      @endif
    </div>
  </aside>

  <main id="main" class="auth-main" tabindex="-1">
    <div class="auth-top">{{ $top ?? '' }}</div>

    <div class="auth-center">
      <div class="auth-form">
        {{ $slot }}
      </div>
    </div>

    <footer class="auth-foot">
      <span>© {{ now()->year }} {{ $theme->string('theme.legal_name', $brand) }}</span>
      <nav aria-label="Legal">
        <a href="{{ route('privacy') }}">Privacy</a>
        <a href="{{ route('terms') }}">Terms</a>
        @if ($theme->string('theme.support_email') !== '')
          <a href="mailto:{{ $theme->string('theme.support_email') }}">Help</a>
        @endif
      </nav>
    </footer>
  </main>
</div>
</body>
</html>
