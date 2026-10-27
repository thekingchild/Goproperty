{{--
    An invitation link that cannot be used (AcceptInvitationController):
    unknown, already used, revoked or expired all look the same, so links
    cannot be probed.
--}}
<x-layouts::auth-split :title="__('Invitation no longer valid')" :activity="false">
  <x-slot:top><span>Already a member?</span><a href="{{ route('login') }}">Sign in</a></x-slot:top>
  <x-slot:mobileLink><a class="auth-panel__mobile-link" href="{{ route('login') }}">Sign in</a></x-slot:mobileLink>

  <header class="auth-heading">
    <span class="auth-heading__icon" aria-hidden="true"><svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 8v5M12 16h.01"/></svg></span>
    <h1>This invitation link no longer works</h1>
    <p>It may have been used already, revoked, or it expired: invitation links work once and for 14 days.</p>
  </header>

  <ul class="tips">
    <li>Already accepted it? <a class="link" href="{{ route('login') }}">Sign in</a> to open your workspace.</li>
    <li>Otherwise ask the person who invited you to send the invitation again.</li>
  </ul>
</x-layouts::auth-split>
