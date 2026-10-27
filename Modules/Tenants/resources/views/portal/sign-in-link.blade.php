{{--
    An agency's one-time sign-in link (Modules\Tenants\Http\Controllers\SignInLinkController,
    ONBOARDING_COMPLIANCE.md § 2). Opening it changes nothing (link previews
    cannot use it up); the tenant confirms, may choose a new password, and is
    signed in. It works once, for 24 hours.
--}}
@php($invalid = fn (string $field): bool => $errors->has($field))
<x-layouts::auth-split :title="__('Sign in to your tenant portal')" :activity="false">
  <x-slot:panel>
    <p class="auth-panel__title">Your rentals with <span>{{ $organization->name }}</span></p>
    <p class="trustline"><span>Single-use link</span><span aria-hidden="true">·</span><span>No SMS codes</span></p>
  </x-slot:panel>

  <header class="auth-heading">
    <span class="auth-heading__icon" aria-hidden="true"><svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/><path d="M10 17l5-5-5-5M15 12H3"/></svg></span>
    <h1>Sign in, {{ $tenant->first_name }}</h1>
    <p><b>{{ $organization->name }}</b> made this one-time sign-in link for you. It works once.</p>
  </header>

  <form class="stack" method="post" action="{{ route('portal.sign-in-links.use', ['token' => $token]) }}" data-busy-form novalidate>
    @csrf
    <div class="field">
      <label for="password">New password (optional)</label>
      <div class="control">
        <svg class="control__icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/></svg>
        <input class="input input--pw" id="password" name="password" type="password" autocomplete="new-password" minlength="10" aria-describedby="password-hint @if ($invalid('password')) password-error @endif" @if ($invalid('password')) aria-invalid="true" @endif>
        <button class="eye" type="button" aria-label="Show password" aria-pressed="false" aria-controls="password" data-eye>
          <svg data-eye-open width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/></svg>
          <svg data-eye-closed hidden width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 3l18 18M10.6 5.1A10.8 10.8 0 0 1 12 5c6.5 0 10 7 10 7a17 17 0 0 1-3.1 3.9M6.6 6.6A17.4 17.4 0 0 0 2 12s3.5 7 10 7a9.7 9.7 0 0 0 5.4-1.6M9.9 9.9a3 3 0 0 0 4.2 4.2"/></svg>
        </button>
      </div>
      <p class="hint" id="password-hint">Forgot your password? Choose a new one (at least 10 characters). Leave it empty to keep the one you have.</p>
      @error('password')<p class="hint hint--error" id="password-error">{{ $message }}</p>@enderror
    </div>

    <button class="btn-primary" type="submit" data-test="use-sign-in-link"><span class="btn-label">Sign in</span><span class="spinner" hidden aria-hidden="true"></span></button>
    <p class="fineprint">Not {{ $tenant->first_name }}? Close this page: the link is only for them.</p>
  </form>
</x-layouts::auth-split>
