{{--
    The signup wizard's page (ONBOARDING_COMPLIANCE.md § 2) in the sign-in
    pages' split shell (UX_DESIGN.md § 3, docs/design/templates/login.html):
    the brand panel lists the steps, the form column holds the current one.
    One root element, so it can be a Livewire component's whole view.
--}}
@props(['current'])
@php
    $site = app(\App\Marketing\PublicSite::class);
    $theme = $site->theme();
    $brand = $theme->name();
    $photo = app(\App\Theme\BrandAssets::class)->url($theme, 'auth');
    $user = auth()->user();
@endphp
<div class="auth-shell auth-shell--wizard">
  <aside @class(['auth-panel', 'has-photo' => $photo !== null]) aria-label="{{ $brand }}">
    @if ($photo !== null)
      <img class="bg-photo" src="{{ $photo }}" alt="">
    @endif
    <div class="bg-overlay" aria-hidden="true"></div>
    <div class="auth-panel__art" aria-hidden="true"><img src="{{ asset('images/skyline.svg') }}" alt=""></div>
    <div class="auth-panel__shade" aria-hidden="true"></div>

    @include('marketing.partials.brand', ['tone' => 'dark'])
    @if ($user !== null)
      <form method="post" action="{{ route('logout') }}">@csrf<button class="auth-panel__mobile-link btn-bare" type="submit">Sign out</button></form>
    @endif

    <div class="auth-panel__body">
      <p class="auth-panel__title">Set up {{ $brand }} <span>in a few minutes</span></p>
      <x-onboarding.steps :current="$current" />
      <p class="trustline"><span>{{ app(\App\Marketing\PlanCatalogue::class)->trialDays() }}-day free trial</span><span aria-hidden="true">·</span><span>No card needed</span><span aria-hidden="true">·</span><span>Tenants pay you directly</span></p>
    </div>
  </aside>

  <main id="main" class="auth-main" tabindex="-1">
    <div class="auth-top">
      @if ($user !== null)
        <span>Signed in as {{ $user->email ?? $user->phone }}</span>
        <form method="post" action="{{ route('logout') }}">@csrf<button class="link" type="submit">Sign out</button></form>
      @endif
    </div>

    <div class="auth-center auth-center--wide">
      <div class="auth-form">
        <p class="wizard-progress"><b>Step {{ $current->number() }} of {{ \App\Enums\OnboardingStep::count() }}</b> · {{ $current->label() }}</p>
        {{ $slot }}
      </div>
    </div>

    <footer class="auth-foot auth-foot--wide">
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
