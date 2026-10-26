{{-- Reset password (UX_DESIGN.md § 3.3), from the emailed link. --}}
@php
    $email = (string) request('email', old('email', ''));
    $rules = \Illuminate\Validation\Rules\Password::defaults();
@endphp
<x-layouts::auth-split :title="__('Choose a new password')">
  <x-slot:top><a href="{{ route('login') }}">← Back to sign in</a></x-slot:top>
  <x-slot:mobileLink><a class="auth-panel__mobile-link" href="{{ route('login') }}">Sign in</a></x-slot:mobileLink>

  <header class="auth-heading">
    <h1>Choose a new password</h1>
    <p>At least 10 characters. Avoid passwords you use elsewhere.@if ($email !== '') For <b>{{ $email }}</b>.@endif</p>
  </header>

  @foreach (['email', 'password', 'token'] as $field)
    @error($field)
      @include('pages.auth.partials.alert', ['message' => $message])
    @enderror
  @endforeach

  <form class="stack" method="post" action="{{ route('password.update') }}" data-busy-form>
    @csrf
    <input type="hidden" name="token" value="{{ request()->route('token') }}">
    @if ($email !== '')
      <input type="hidden" name="email" value="{{ $email }}">
    @else
      <div class="field">
        <label for="email">Email</label>
        <div class="control">
          <svg class="control__icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/></svg>
          <input class="input" id="email" name="email" type="email" autocomplete="username" required>
        </div>
      </div>
    @endif
    <div class="field">
      <label for="password">New password</label>
      <div class="control">
        <svg class="control__icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/></svg>
        <input class="input input--pw" id="password" name="password" type="password" autocomplete="new-password" minlength="10" passwordrules="{{ $rules->toPasswordRulesString() }}" aria-describedby="caps" required autofocus>
        <button class="eye" type="button" aria-label="Show password" aria-pressed="false" aria-controls="password" data-eye>
          <svg data-eye-open width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/></svg>
          <svg data-eye-closed hidden width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 3l18 18M10.6 5.1A10.8 10.8 0 0 1 12 5c6.5 0 10 7 10 7a17 17 0 0 1-3.1 3.9M6.6 6.6A17.4 17.4 0 0 0 2 12s3.5 7 10 7a9.7 9.7 0 0 0 5.4-1.6M9.9 9.9a3 3 0 0 0 4.2 4.2"/></svg>
        </button>
      </div>
      <p class="hint hint--warn" id="caps" hidden data-caps>Caps Lock is on</p>
    </div>
    <div class="field">
      <label for="password_confirmation">Type it again</label>
      <div class="control">
        <svg class="control__icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/></svg>
        <input class="input" id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" required>
      </div>
    </div>
    <button class="btn-primary" type="submit"><span class="btn-label">Save new password</span><span class="spinner" hidden aria-hidden="true"></span></button>
  </form>
</x-layouts::auth-split>
