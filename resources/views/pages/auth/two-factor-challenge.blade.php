{{--
    2FA challenge (UX_DESIGN.md § 3.3), from the approved design
    docs/design/templates/login-2fa.html: six code boxes (auto-advance,
    paste, auto-submit), "Don't ask again on this device", and other ways
    to verify: passkey, recovery code, help. Codes come from an
    authenticator app; no SMS.
--}}
@php
    $challenged = \App\Models\User::query()->find(session('login.id'));
    $mask = function (?string $email, ?string $phone): ?string {
        if ($email !== null && str_contains($email, '@')) {
            [$name, $domain] = explode('@', $email, 2);

            return mb_substr($name, 0, 1).'•••@'.$domain;
        }

        return $phone !== null ? substr($phone, 0, 4).' ••• '.substr($phone, -4) : null;
    };
    $who = $challenged ? $mask($challenged->email, $challenged->phone) : null;
    $recovery = $errors->has('recovery_code') || old('recovery_code') !== null;
    $settings = app(\App\Settings\SettingsStore::class);
    $theme = app(\App\Theme\ThemeContext::class)->current();
    $passkeys = \Laravel\Fortify\Features::enabled(\Laravel\Fortify\Features::passkeys()) && $settings->get('auth.passkeys_enabled') !== false
        && $challenged !== null && $challenged->passkeys()->exists();
    $days = $settings->get('auth.trust_device_days');
    $days = is_int($days) ? $days : 30;
@endphp
<x-layouts::auth-split :title="__('Two-step verification')" :activity="false">
  <x-slot:panel>
    <p class="auth-panel__title">One more step to <span>keep your money safe.</span></p>
    <ul class="benefits">
      <li><span class="benefits__icon" aria-hidden="true"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="7" y="2" width="10" height="20" rx="2"/><path d="M11 18h2"/></svg></span>Codes come from your authenticator app — no SMS</li>
      <li><span class="benefits__icon" aria-hidden="true"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 21v-1a6 6 0 0 1 9.5-4.9"/><circle cx="9" cy="7" r="4"/><circle cx="17.5" cy="14.5" r="2.5"/><path d="M17.5 17v5M17.5 19.5H20"/></svg></span>Add a passkey to skip this step next time</li>
      <li><span class="benefits__icon" aria-hidden="true"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3 4 6v6c0 5 3.5 8 8 9 4.5-1 8-4 8-9V6z"/></svg></span>We email you whenever a new device signs in</li>
    </ul>
  </x-slot:panel>
  <x-slot:top><a href="{{ route('login') }}">← Use a different account</a></x-slot:top>
  <x-slot:mobileLink><a class="auth-panel__mobile-link" href="{{ route('login') }}">Different account</a></x-slot:mobileLink>

  <header class="auth-heading">
    <span class="auth-heading__icon" aria-hidden="true"><svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3 4 6v6c0 5 3.5 8 8 9 4.5-1 8-4 8-9V6z"/><path d="m9 12 2 2 4-4"/></svg></span>
    <h1>Two-step verification</h1>
    <p data-sub-code @if ($recovery) hidden @endif>Enter the 6-digit code from your authenticator app{!! $who !== null ? ' for <b>'.e($who).'</b>' : '' !!}.</p>
    <p data-sub-recovery @unless ($recovery) hidden @endunless>Enter one of the recovery codes you saved when you turned on two-step verification.</p>
  </header>

  @if ($errors->has('code') || $errors->has('recovery_code'))
    <div class="alert" role="alert" data-error>
      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 8v5M12 16h.01"/></svg>
      <span>{{ $errors->has('code') ? "That code didn't work. Codes change every 30 seconds — check your phone's time is correct." : "That recovery code didn't work. Each code works only once." }}</span>
    </div>
  @endif

  <form class="stack" method="post" action="{{ route('two-factor.login.store') }}" data-code-form data-busy-form @if ($recovery) hidden @endif>
    @csrf
    <fieldset class="field otp-fieldset">
      <legend class="label">Authentication code</legend>
      {{-- One real field for browsers without the script; the script turns it into six boxes. --}}
      <input class="input input--code" type="text" name="code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="one-time-code" aria-label="6-digit code" data-code @unless ($recovery) autofocus @endunless @error('code') aria-invalid="true" @enderror>
      <div class="otp" data-otp hidden @error('code') aria-invalid="true" @enderror>
        @for ($i = 1; $i <= 6; $i++)
          <input type="text" inputmode="numeric" pattern="[0-9]" maxlength="1" aria-label="Digit {{ $i }}" @if ($i === 1) autocomplete="one-time-code" @endif>
        @endfor
      </div>
      <p class="hint">Tip: you can paste the whole code.</p>
    </fieldset>
    <label class="check"><input type="checkbox" name="trust_device" value="1"> Don't ask again on this device for {{ $days }} days</label>
    <button class="btn-primary" type="submit" data-submit><span class="btn-label">Verify and continue</span><span class="spinner" hidden aria-hidden="true"></span></button>
  </form>

  <form class="stack" method="post" action="{{ route('two-factor.login.store') }}" data-recovery-form data-busy-form @unless ($recovery) hidden @endunless>
    @csrf
    <div class="field">
      <label for="recovery">Recovery code</label>
      <div class="control">
        <svg class="control__icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="8" cy="15" r="4"/><path d="m10.8 12.2 8.2-8.2M17 6l2 2M15 8l2 2"/></svg>
        <input class="input input--mono" id="recovery" name="recovery_code" type="text" autocomplete="off" autocapitalize="none" spellcheck="false" placeholder="abcde-12345" @if ($recovery) autofocus @endif>
      </div>
      <p class="hint">Each recovery code works once. You saved them when you turned on 2FA.</p>
    </div>
    <label class="check"><input type="checkbox" name="trust_device" value="1"> Don't ask again on this device for {{ $days }} days</label>
    <button class="btn-primary" type="submit"><span class="btn-label">Use recovery code</span><span class="spinner" hidden aria-hidden="true"></span></button>
  </form>

  <div class="alt-methods">
    <p>Other ways to verify</p>
    @if ($passkeys)
      <div data-passkey-block data-options="{{ route('passkey.login-options') }}" data-submit="{{ route('passkey.login') }}" data-home="{{ config('fortify.home') }}" hidden>
        <button class="alt-btn" type="button" data-passkey>
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2 21v-1a6 6 0 0 1 9.5-4.9"/><circle cx="9" cy="7" r="4"/><circle cx="17.5" cy="14.5" r="2.5"/><path d="M17.5 17v5M17.5 19.5H20"/></svg>
          <span data-passkey-label>Use a passkey</span>
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m9 6 6 6-6 6"/></svg>
        </button>
      </div>
    @endif
    <button class="alt-btn" type="button" data-toggle-recovery hidden>
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="8" cy="15" r="4"/><path d="m10.8 12.2 8.2-8.2M17 6l2 2M15 8l2 2"/></svg>
      <span data-toggle-label>{{ $recovery ? 'Use my authenticator app' : 'Use a recovery code' }}</span>
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m9 6 6 6-6 6"/></svg>
    </button>
    @if ($theme->string('theme.support_email') !== '')
      <a class="alt-btn" href="mailto:{{ $theme->string('theme.support_email') }}?subject={{ rawurlencode('Lost my 2FA phone and recovery codes') }}">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M9.5 9a2.5 2.5 0 1 1 3.5 2.3c-.6.3-1 .9-1 1.7M12 17h.01"/></svg>
        Lost your phone and codes? Get help
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m9 6 6 6-6 6"/></svg>
      </a>
    @endif
  </div>
</x-layouts::auth-split>
