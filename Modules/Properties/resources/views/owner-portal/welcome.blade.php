{{--
    After an owner creates their account from an invitation (P03-15): offer a
    passkey (optional; Settings → Security, the password was just confirmed),
    then the owner portal (/owner).
--}}
<x-layouts::auth-split :title="__('Your account is ready')" :activity="false">
  <header class="auth-heading">
    <span class="auth-heading__icon" aria-hidden="true"><svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg></span>
    <h1>Your account is ready</h1>
    <p>Next time, sign in with your {{ $user->email !== null ? 'email address' : 'phone number' }} and password.</p>
  </header>

  <div class="stack">
    @if ($passkeys)
      <p class="switch">Sign in faster with a passkey: your fingerprint, face or screen lock, no password to remember. Optional.</p>
      <a class="btn-passkey" href="{{ route('security.edit') }}" data-test="add-passkey">Add a passkey</a>
    @endif
    <a class="btn-primary" href="{{ route('owner.home') }}" data-test="open-portal"><span class="btn-label">Go to my properties</span></a>
  </div>
</x-layouts::auth-split>
