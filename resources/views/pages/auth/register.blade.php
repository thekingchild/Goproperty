{{--
    Signup, account step (ONBOARDING_COMPLIANCE.md § 2 step 1), on the
    sign-in pages' split layout (UX_DESIGN.md § 3, login.html). Name, email,
    Nigerian mobile number (a contact and sign-in name, never sent an SMS
    code) and password. The bot check shows when SignupBotCheck asks for it
    ($showCheck). Errors are about the form only: the page never says
    whether an address already has an account.
--}}
@php
    $turnstile = app(\App\Auth\SignIn\Turnstile::class);
    $brand = app(\App\Theme\ThemeContext::class)->current()->name();
    $trialDays = app(\App\Marketing\PlanCatalogue::class)->trialDays();
    $invalid = fn (string $field): bool => $errors->has($field);
@endphp
<x-layouts::auth-split :title="__('Start free trial')" :activity="false">
  @if ($showCheck)
    <x-slot:head>
      <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
    </x-slot:head>
  @endif
  <x-slot:top><span>Already have an account?</span><a href="{{ route('login') }}">Sign in</a></x-slot:top>
  <x-slot:mobileLink><a class="auth-panel__mobile-link" href="{{ route('login') }}">Sign in</a></x-slot:mobileLink>
  <x-slot:panel>
    <p class="auth-panel__title">Start your <span>{{ $trialDays }}-day free trial</span></p>
    <x-onboarding.steps :current="\App\Enums\OnboardingStep::Account" />
    <p class="trustline"><span>No card needed</span><span aria-hidden="true">·</span><span>Tenants pay you directly</span><span aria-hidden="true">·</span><span>No SMS codes</span></p>
  </x-slot:panel>

  <header class="auth-heading">
    <p class="wizard-progress"><b>Step 1 of {{ \App\Enums\OnboardingStep::count() }}</b> · {{ \App\Enums\OnboardingStep::Account->label() }}</p>
    <h1>Start your free trial</h1>
    <p>{{ $trialDays }} days with every feature. No card needed.</p>
  </header>

  @error('turnstile')
    @include('pages.auth.partials.alert', ['message' => $message])
  @enderror

  <form class="stack" method="post" action="{{ route('register.store') }}" data-busy-form novalidate>
    @csrf
    <div class="field">
      <label for="name">Your full name</label>
      <div class="control">
        <svg class="control__icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/></svg>
        <input class="input" id="name" name="name" type="text" value="{{ old('name') }}" autocomplete="name" maxlength="255" required autofocus @if ($invalid('name')) aria-invalid="true" aria-describedby="name-error" @endif>
      </div>
      @error('name')<p class="hint hint--error" id="name-error">{{ $message }}</p>@enderror
    </div>

    <div class="field">
      <label for="email">Work email</label>
      <div class="control">
        <svg class="control__icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/></svg>
        <input class="input" id="email" name="email" type="email" value="{{ old('email') }}" autocomplete="email" autocapitalize="none" spellcheck="false" placeholder="you@agency.ng" maxlength="255" required @if ($invalid('email')) aria-invalid="true" aria-describedby="email-error" @endif>
      </div>
      @error('email')<p class="hint hint--error" id="email-error">{{ $message }}</p>@enderror
    </div>

    <div class="field">
      <label for="phone">Mobile number</label>
      <div class="control">
        <svg class="control__icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="7" y="2" width="10" height="20" rx="2"/><path d="M11 18h2"/></svg>
        <input class="input" id="phone" name="phone" type="tel" inputmode="tel" value="{{ old('phone') }}" autocomplete="tel" placeholder="0803 123 4567" maxlength="32" required aria-describedby="phone-hint @if ($invalid('phone')) phone-error @endif" @if ($invalid('phone')) aria-invalid="true" @endif>
      </div>
      <p class="hint" id="phone-hint">A Nigerian mobile number. You can sign in with it too. We never send SMS codes.</p>
      @error('phone')<p class="hint hint--error" id="phone-error">{{ $message }}</p>@enderror
    </div>

    <div class="field">
      <label for="password">Password</label>
      <div class="control">
        <svg class="control__icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/></svg>
        <input class="input input--pw" id="password" name="password" type="password" autocomplete="new-password" minlength="10" required aria-describedby="password-hint caps @if ($invalid('password')) password-error @endif" @if ($invalid('password')) aria-invalid="true" @endif>
        <button class="eye" type="button" aria-label="Show password" aria-pressed="false" aria-controls="password" data-eye>
          <svg data-eye-open width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/></svg>
          <svg data-eye-closed hidden width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 3l18 18M10.6 5.1A10.8 10.8 0 0 1 12 5c6.5 0 10 7 10 7a17 17 0 0 1-3.1 3.9M6.6 6.6A17.4 17.4 0 0 0 2 12s3.5 7 10 7a9.7 9.7 0 0 0 5.4-1.6M9.9 9.9a3 3 0 0 0 4.2 4.2"/></svg>
        </button>
      </div>
      <p class="hint" id="password-hint">At least 10 characters.</p>
      <p class="hint hint--warn" id="caps" hidden data-caps>
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 4 4 13h5v7h6v-7h5z"/></svg>Caps Lock is on
      </p>
      @error('password')<p class="hint hint--error" id="password-error">{{ $message }}</p>@enderror
    </div>

    @if ($showCheck)
      <div class="field" data-turnstile-wrap>
        <div class="turnstile cf-turnstile" data-turnstile data-sitekey="{{ $turnstile->siteKey() }}" data-callback="gpTurnstileDone" data-expired-callback="gpTurnstileExpired" role="group" aria-label="Security check"></div>
      </div>
    @endif

    <button class="btn-primary" type="submit" data-submit data-test="register-user-button"><span class="btn-label">Create account</span><span class="spinner" hidden aria-hidden="true"></span></button>

    <p class="fineprint">By creating an account you agree to the <a href="{{ route('terms') }}">Terms</a> and <a href="{{ route('privacy') }}">Privacy policy</a>.</p>
  </form>

  <p class="switch switch--mobile">Already have an account? <a href="{{ route('login') }}">Sign in</a></p>
</x-layouts::auth-split>
