{{--
    Customer sign-in (UX_DESIGN.md § 3.1), from the approved design
    docs/design/templates/login.html. One field takes an email or a phone
    number (a sign-in name only: no SMS is sent). States: default, wrong
    details (one generic message), bot check (Turnstile, button disabled
    until it passes) and locked (paused, with a password-reset link).
--}}
@php
    $security = app(\App\Auth\SignIn\SignInSecurity::class);
    $turnstile = app(\App\Auth\SignIn\Turnstile::class);
    $settings = app(\App\Settings\SettingsStore::class);
    $brand = app(\App\Theme\ThemeContext::class)->current()->name();
    $locked = session()->has(\App\Auth\SignIn\GuardSignIn::LOCKED);
    $showCheck = $turnstile->configured() && (session(\App\Auth\SignIn\GuardSignIn::SHOW_CHECK) || $security->needsCheck(null, request()->ip()));
    $passkeys = \Laravel\Fortify\Features::enabled(\Laravel\Fortify\Features::passkeys()) && $settings->get('auth.passkeys_enabled') !== false;
    $error = $errors->first('identifier') ?: $errors->first('password');
@endphp
<x-layouts::auth-split :title="__('Log in')">
  @if ($showCheck)
    <x-slot:head>
      <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
    </x-slot:head>
  @endif
  <x-slot:top><span>New to {{ $brand }}?</span><a href="{{ route('register') }}">Start free trial</a></x-slot:top>
  <x-slot:mobileLink><a class="auth-panel__mobile-link" href="{{ route('register') }}">Start free trial</a></x-slot:mobileLink>

  <header class="auth-heading">
    <h1>Welcome back</h1>
    <p>Sign in to your {{ $brand }} account.</p>
  </header>

  @if (session('status'))
    <div class="alert alert--info" role="status">{{ session('status') }}</div>
  @endif

  @if ($passkeys)
    {{-- Shown by the script only when the browser supports passkeys. --}}
    <div class="stack" data-passkey-block data-options="{{ route('passkey.login-options') }}" data-submit="{{ route('passkey.login') }}" data-home="{{ config('fortify.home') }}" hidden>
      <button class="btn-passkey" type="button" data-passkey>
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2 21v-1a6 6 0 0 1 9.5-4.9"/><circle cx="9" cy="7" r="4"/><circle cx="17.5" cy="14.5" r="2.5"/><path d="M17.5 17v5M17.5 19.5H20"/></svg>
        <span data-passkey-label>Sign in with a passkey</span>
      </button>
      <div class="divider">or use your password</div>
    </div>
    <div class="alert" role="alert" hidden data-passkey-error>
      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 8v5M12 16h.01"/></svg>
      <span data-passkey-error-text></span>
    </div>
  @endif

  @if ($error)
    <div class="alert" role="alert" data-error>
      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 8v5M12 16h.01"/></svg>
      @if ($locked)
        <span><b>Too many attempts</b>{{ $error }} You can <a class="link" href="{{ route('password.request') }}">reset your password</a> now.</span>
      @else
        <span>{{ $error }}</span>
      @endif
    </div>
  @endif

  <form class="stack" method="post" action="{{ route('login.store') }}" data-busy-form>
    @csrf
    <div class="field">
      <label for="identifier">Email or phone number</label>
      <div class="control">
        <svg class="control__icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/></svg>
        <input class="input" id="identifier" name="identifier" type="text" value="{{ old('identifier') }}" autocomplete="username webauthn" autocapitalize="none" spellcheck="false" placeholder="you@agency.ng or 0803 123 4567" required autofocus @if ($error) aria-invalid="true" @endif>
      </div>
    </div>
    <div class="field">
      <div class="field__row">
        <label for="password">Password</label>
        <a class="link" href="{{ route('password.request') }}">Forgot password?</a>
      </div>
      <div class="control">
        <svg class="control__icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/></svg>
        <input class="input input--pw" id="password" name="password" type="password" autocomplete="current-password" aria-describedby="caps" required>
        <button class="eye" type="button" aria-label="Show password" aria-pressed="false" aria-controls="password" data-eye>
          <svg data-eye-open width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/></svg>
          <svg data-eye-closed hidden width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 3l18 18M10.6 5.1A10.8 10.8 0 0 1 12 5c6.5 0 10 7 10 7a17 17 0 0 1-3.1 3.9M6.6 6.6A17.4 17.4 0 0 0 2 12s3.5 7 10 7a9.7 9.7 0 0 0 5.4-1.6M9.9 9.9a3 3 0 0 0 4.2 4.2"/></svg>
        </button>
      </div>
      <p class="hint hint--warn" id="caps" hidden data-caps>
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 4 4 13h5v7h6v-7h5z"/></svg>Caps Lock is on
      </p>
    </div>

    <label class="check"><input type="checkbox" name="remember" value="1" @checked(old('remember'))> Keep me signed in on this device</label>

    @if ($showCheck)
      <div class="field" data-turnstile-wrap>
        <div class="turnstile cf-turnstile" data-turnstile data-sitekey="{{ $turnstile->siteKey() }}" data-callback="gpTurnstileDone" data-expired-callback="gpTurnstileExpired" role="group" aria-label="Security check"></div>
      </div>
    @endif

    <button class="btn-primary" type="submit" data-submit @disabled($locked)><span class="btn-label">Sign in</span><span class="spinner" hidden aria-hidden="true"></span></button>
  </form>

  <p class="switch switch--mobile">New to {{ $brand }}? <a href="{{ route('register') }}">Start free trial</a></p>
</x-layouts::auth-split>
