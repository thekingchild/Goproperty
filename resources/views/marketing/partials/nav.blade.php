{{--
    The marketing nav bar and phone menu (UX_DESIGN.md § 4). On the landing
    page its links jump to the sections; on other pages they lead back to
    them, and Pricing to the pricing page (P06-11).
--}}
@php
    $landing ??= false;
    $onPricing = request()->routeIs('pricing');
    $anchor = fn (string $id): string => $landing ? '#'.$id : route('home').'#'.$id;
@endphp
<div class="nav-bar" data-nav>
  @include('marketing.partials.announcement')
  <div class="wrap nav">
    @include('marketing.partials.brand', ['tone' => 'dark'])
    <nav class="nav__links" aria-label="Primary">
      <a href="{{ $anchor('product') }}">Product</a>
      <a href="{{ $anchor('features') }}">Features</a>
      <a href="{{ $anchor('payments') }}">Payments</a>
      <a href="{{ $landing ? '#pricing' : route('pricing') }}"@if ($onPricing) aria-current="page"@endif>Pricing</a>
      <a href="{{ $anchor('faq') }}">FAQ</a>
    </nav>
    <div class="nav__actions">
      <a class="nav__login" href="{{ route('login') }}">Log in</a>
      <a class="pill pill--login" href="{{ route('login') }}">Log in</a>
      <a class="pill pill--trial" href="{{ $trialUrl }}">Start free trial</a>
      <button class="menu-btn" type="button" aria-label="Open menu" aria-haspopup="dialog" data-menu-open>
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M4 7h16M4 12h16M4 17h16"/></svg>
      </button>
    </div>
  </div>
  <dialog class="menu" aria-labelledby="menu-title" data-menu>
    <div class="menu__head">
      <p id="menu-title">{{ $brand }}</p>
      <button class="menu__close" type="button" aria-label="Close menu" data-menu-close><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6 6 18"/></svg></button>
    </div>
    <nav aria-label="Menu">
      <a href="{{ $anchor('product') }}">Product</a>
      <a href="{{ $anchor('features') }}">Features</a>
      <a href="{{ $anchor('payments') }}">Payments</a>
      <a href="{{ $landing ? '#pricing' : route('pricing') }}"@if ($onPricing) aria-current="page"@endif>Pricing</a>
      <a href="{{ $anchor('faq') }}">FAQ</a>
    </nav>
    <div class="menu__ctas">
      <a class="btn btn--primary" href="{{ $trialUrl }}">Start free trial</a>
      <a class="btn btn--ghost" href="{{ route('login') }}">Log in</a>
    </div>
  </dialog>
</div>
