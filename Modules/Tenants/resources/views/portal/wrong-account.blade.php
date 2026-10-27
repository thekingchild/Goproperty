{{--
    Someone signed in with another account opened a tenant invitation or
    sign-in link: it is bound to one email address, phone number or account.
--}}
<x-layouts::auth-split :title="__('Link for another account')" :activity="false">
  <header class="auth-heading">
    <span class="auth-heading__icon" aria-hidden="true"><svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/></svg></span>
    <h1>This link is for another account</h1>
    <p>The link from <b>{{ $organization->name }}</b> belongs to a different {{ $kind === 'sign-in' ? 'account' : 'email address or phone number' }} from the account you are signed in with. Sign out, then open the link again.</p>
  </header>

  <form method="post" action="{{ route('logout') }}" class="stack">
    @csrf
    <button class="btn-primary" type="submit" data-test="sign-out"><span class="btn-label">Sign out</span><span class="spinner" hidden aria-hidden="true"></span></button>
  </form>
</x-layouts::auth-split>
