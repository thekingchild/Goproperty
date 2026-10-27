{{--
    Someone signed in with another account opened an invitation link
    (AcceptInvitationController): the invitation is bound to the address it
    was sent to, so it cannot be accepted from this account.
--}}
<x-layouts::auth-split :title="__('Invitation for another account')" :activity="false">
  <header class="auth-heading">
    <span class="auth-heading__icon" aria-hidden="true"><svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/></svg></span>
    <h1>This invitation is for another account</h1>
    <p>The invitation to join <b>{{ $organization->name }}</b> was sent to a different email address from the one you are signed in with. Sign out, then open the link from the email again.</p>
  </header>

  <form method="post" action="{{ route('logout') }}" class="stack">
    @csrf
    <button class="btn-primary" type="submit" data-test="sign-out"><span class="btn-label">Sign out</span><span class="spinner" hidden aria-hidden="true"></span></button>
  </form>
</x-layouts::auth-split>
