{{-- Forgot password (UX_DESIGN.md § 3.3): one field, and the same reply whatever is typed. --}}
@php($brand = app(\App\Theme\ThemeContext::class)->current()->name())
<x-layouts::auth-split :title="__('Forgot password')">
  <x-slot:top><a href="{{ route('login') }}">← Back to sign in</a></x-slot:top>
  <x-slot:mobileLink><a class="auth-panel__mobile-link" href="{{ route('login') }}">Sign in</a></x-slot:mobileLink>

  <header class="auth-heading">
    <h1>Reset your password</h1>
    <p>Enter the email or phone number you sign in with. We'll send you a link to choose a new password.</p>
  </header>

  @if (session('status'))
    @include('pages.auth.partials.alert', ['tone' => 'info', 'message' => session('status')])
  @endif
  @error('identifier')
    @include('pages.auth.partials.alert', ['message' => $message])
  @enderror

  <form class="stack" method="post" action="{{ route('password.email') }}" data-busy-form>
    @csrf
    <div class="field">
      <label for="identifier">Email or phone number</label>
      <div class="control">
        <svg class="control__icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/></svg>
        <input class="input" id="identifier" name="identifier" type="text" value="{{ old('identifier') }}" autocomplete="username" autocapitalize="none" spellcheck="false" placeholder="you@agency.ng or 0803 123 4567" required autofocus>
      </div>
    </div>
    <button class="btn-primary" type="submit"><span class="btn-label">Send reset link</span><span class="spinner" hidden aria-hidden="true"></span></button>
  </form>

  <p class="switch">Remembered it? <a href="{{ route('login') }}">Back to sign in</a></p>
</x-layouts::auth-split>
