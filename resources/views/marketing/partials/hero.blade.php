{{--
    Hero (approved design): heading, badge, trust items and lede from
    site.* settings. With theme.hero_image the photo shows under the overlay
    and the skyline art is hidden (PLATFORM_CONSOLE.md § 9.2).
--}}
@php
    $assets = app(\App\Theme\BrandAssets::class);
    $heroPhoto = $assets->url($theme, 'hero');
@endphp
<header @class(['site-top', 'has-photo' => $heroPhoto !== null]) data-hero>
  @if ($heroPhoto !== null)
    <img class="bg-photo" src="{{ $heroPhoto }}" srcset="{{ $assets->srcset($theme, 'hero') }}" sizes="100vw" alt="" fetchpriority="high">
  @endif
  <div class="bg-overlay" aria-hidden="true"></div>
  <div class="site-top__art" aria-hidden="true"><img src="{{ asset('images/skyline.svg') }}" alt=""></div>
  <div class="site-top__shade" aria-hidden="true"></div>
  <div class="nav-spacer" aria-hidden="true"></div>

  <section class="wrap hero" aria-labelledby="hero-title">
    <div>
      @if ($theme->string('site.hero_badge') !== '')
        <p class="badge"><span class="badge__dot" aria-hidden="true"></span>{{ $theme->string('site.hero_badge') }}</p>
      @endif
      <h1 id="hero-title" class="hero__title"><span>{{ $theme->string('site.hero_heading') }}</span><span>{{ $theme->string('site.hero_subheading') }}</span></h1>
      <ul class="trust" aria-label="Trial terms">
        @foreach ($theme->list('site.hero_trust') as $item)
          <li><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m5 12 5 5L20 7"/></svg>{{ $item }}</li>
        @endforeach
      </ul>
    </div>
    <div class="hero__side">
      <p class="hero__lede">{{ $theme->string('site.hero_lede') }}</p>
      <div class="hero__cta">
        <a class="hero__button" href="{{ $trialUrl }}">Start free trial
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.25" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
        </a>
        <p class="hero__alt">Prefer a walkthrough? <a href="{{ $demoUrl }}">Book a demo</a></p>
      </div>
    </div>
  </section>
</header>
