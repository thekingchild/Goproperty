{{-- Confirm password before a sensitive change (UX_DESIGN.md § 3.3). --}}
<x-layouts::auth-split :title="__('Confirm your password')">
  <header class="auth-heading">
    <span class="auth-heading__icon" aria-hidden="true"><svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/></svg></span>
    <h1>Confirm your password</h1>
    <p>This is a sensitive change, so please enter your password again.</p>
  </header>

  @error('password')
    @include('pages.auth.partials.alert', ['message' => $message])
  @enderror

  <form class="stack" method="post" action="{{ route('password.confirm.store') }}" data-busy-form>
    @csrf
    <div class="field">
      <label for="password">Password</label>
      <div class="control">
        <svg class="control__icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/></svg>
        <input class="input input--pw" id="password" name="password" type="password" autocomplete="current-password" aria-describedby="caps" required autofocus>
        <button class="eye" type="button" aria-label="Show password" aria-pressed="false" aria-controls="password" data-eye>
          <svg data-eye-open width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/></svg>
          <svg data-eye-closed hidden width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 3l18 18M10.6 5.1A10.8 10.8 0 0 1 12 5c6.5 0 10 7 10 7a17 17 0 0 1-3.1 3.9M6.6 6.6A17.4 17.4 0 0 0 2 12s3.5 7 10 7a9.7 9.7 0 0 0 5.4-1.6M9.9 9.9a3 3 0 0 0 4.2 4.2"/></svg>
        </button>
      </div>
      <p class="hint hint--warn" id="caps" hidden data-caps>Caps Lock is on</p>
    </div>
    <button class="btn-primary" type="submit"><span class="btn-label">Confirm</span><span class="spinner" hidden aria-hidden="true"></span></button>
  </form>
</x-layouts::auth-split>
