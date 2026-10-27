{{--
    A tenant's portal invitation (Modules\Tenants\Http\Controllers\PortalInvitationController,
    UX_DESIGN.md § 3.3 "Accept invitation", ONBOARDING_COMPLIANCE.md § 2 "Tenants"):
    - create: nobody has the address or number yet: name and a password;
    - sign-in: an account has it: sign in first, then come back here;
    - join: signed in as the account the link is for: one button.
    A passkey can be added on the next page. No SMS codes.
--}}
@php
    $inviter = $invitation->invitedBy?->name;
    $invalid = fn (string $field): bool => $errors->has($field);
    $bound = $invitation->channel === \App\Enums\InvitationChannel::Email
        ? $invitation->email
        : ($invitation->phone !== null ? substr($invitation->phone, 0, 4).' ••• '.substr($invitation->phone, -4) : null);
@endphp
<x-layouts::auth-split :title="__('Your tenant portal at :organization', ['organization' => $organization->name])" :activity="false">
  @if ($mode !== 'join')
    <x-slot:top><span>Not you?</span><a href="{{ route('home') }}">Go to the home page</a></x-slot:top>
  @endif
  <x-slot:panel>
    <p class="auth-panel__title">Your rentals with <span>{{ $organization->name }}</span></p>
    <p class="trustline"><span>Single-use link</span><span aria-hidden="true">·</span><span>No SMS codes</span></p>
  </x-slot:panel>

  <header class="auth-heading">
    <span class="auth-heading__icon" aria-hidden="true"><svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 10.5 12 3l9 7.5V21H3z"/><path d="M9 21v-6h6v6"/></svg></span>
    <h1>Welcome, {{ $tenant->first_name }}</h1>
    <p>@if ($inviter)<b>{{ $inviter }}</b> of @endif<b>{{ $organization->name }}</b> invited you to the tenant portal: your rent, bills and receipts in one place.</p>
    @if ($bound)<p>Invitation for <b>{{ $bound }}</b>.</p>@endif
  </header>

  @if ($mode === 'join')
    <form class="stack" method="post" action="{{ route('portal.invitations.accept', ['token' => $token]) }}" data-busy-form>
      @csrf
      <button class="btn-primary" type="submit" data-test="join-button"><span class="btn-label">Add {{ $organization->name }} to my account</span><span class="spinner" hidden aria-hidden="true"></span></button>
    </form>
  @elseif ($mode === 'sign-in')
    <div class="stack">
      <p class="switch">You already have a GoProperty account with this {{ $invitation->channel === \App\Enums\InvitationChannel::Email ? 'email address' : 'phone number' }}. Sign in and you'll come straight back here.</p>
      <a class="btn-primary" href="{{ route('login') }}" data-test="sign-in-to-accept"><span class="btn-label">Sign in to accept</span></a>
      <p class="switch">Forgot your password? <a href="{{ route('password.request') }}">Reset it</a> or ask {{ $organization->name }} for a sign-in link.</p>
    </div>
  @else
    <form class="stack" method="post" action="{{ route('portal.invitations.accept', ['token' => $token]) }}" data-busy-form novalidate>
      @csrf
      <div class="field">
        <label for="name">Your full name</label>
        <div class="control">
          <svg class="control__icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/></svg>
          <input class="input" id="name" name="name" type="text" value="{{ old('name', $tenant->fullName()) }}" autocomplete="name" maxlength="255" required @if ($invalid('name')) aria-invalid="true" aria-describedby="name-error" @endif>
        </div>
        @error('name')<p class="hint hint--error" id="name-error">{{ $message }}</p>@enderror
      </div>

      <div class="field">
        <label for="password">Choose a password</label>
        <div class="control">
          <svg class="control__icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/></svg>
          <input class="input input--pw" id="password" name="password" type="password" autocomplete="new-password" minlength="10" required autofocus aria-describedby="password-hint caps @if ($invalid('password')) password-error @endif" @if ($invalid('password')) aria-invalid="true" @endif>
          <button class="eye" type="button" aria-label="Show password" aria-pressed="false" aria-controls="password" data-eye>
            <svg data-eye-open width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/></svg>
            <svg data-eye-closed hidden width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 3l18 18M10.6 5.1A10.8 10.8 0 0 1 12 5c6.5 0 10 7 10 7a17 17 0 0 1-3.1 3.9M6.6 6.6A17.4 17.4 0 0 0 2 12s3.5 7 10 7a9.7 9.7 0 0 0 5.4-1.6M9.9 9.9a3 3 0 0 0 4.2 4.2"/></svg>
          </button>
        </div>
        <p class="hint" id="password-hint">At least 10 characters. You'll sign in with your {{ $invitation->channel === \App\Enums\InvitationChannel::Email ? 'email address' : 'phone number' }} and this password.</p>
        <p class="hint hint--warn" id="caps" hidden data-caps>
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 4 4 13h5v7h6v-7h5z"/></svg>Caps Lock is on
        </p>
        @error('password')<p class="hint hint--error" id="password-error">{{ $message }}</p>@enderror
      </div>

      <button class="btn-primary" type="submit" data-test="accept-button"><span class="btn-label">Create my account</span><span class="spinner" hidden aria-hidden="true"></span></button>

      <p class="switch">Already have a GoProperty account? <a href="{{ route('login') }}" data-test="sign-in-instead">Sign in</a>, then open this link again.</p>
      <p class="fineprint">By creating an account you agree to the <a href="{{ route('terms') }}">Terms</a> and <a href="{{ route('privacy') }}">Privacy policy</a>.</p>
    </form>
  @endif
</x-layouts::auth-split>
