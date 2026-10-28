{{--
    An owner invitation link that cannot be used (P03-15): unknown, already
    used, revoked or expired all look the same, so links cannot be probed.
--}}
<x-layouts::auth-split :title="__('Link no longer valid')" :activity="false">
  <x-slot:top><span>Have an account?</span><a href="{{ route('login') }}">Sign in</a></x-slot:top>
  <x-slot:mobileLink><a class="auth-panel__mobile-link" href="{{ route('login') }}">Sign in</a></x-slot:mobileLink>

  <header class="auth-heading">
    <span class="auth-heading__icon" aria-hidden="true"><svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 8v5M12 16h.01"/></svg></span>
    <h1>This link no longer works</h1>
    <p>It may have been used already, revoked, or it expired. Invitation links work once, for 14 days.</p>
  </header>

  <ul class="tips">
    <li>Already set up your account? <a class="link" href="{{ route('login') }}">Sign in</a> with your phone number or email address.</li>
    <li>Otherwise ask the agency that manages your buildings for a new link.</li>
  </ul>
</x-layouts::auth-split>
