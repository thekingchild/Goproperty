{{-- Verify email (UX_DESIGN.md § 3.3). --}}
@php($email = auth()->user()?->email)
<x-layouts::auth-split :title="__('Check your email')">
  <x-slot:top>
    <form method="post" action="{{ route('logout') }}">@csrf<button class="link" type="submit">Sign out</button></form>
  </x-slot:top>

  <header class="auth-heading">
    <span class="auth-heading__icon" aria-hidden="true"><svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/></svg></span>
    <h1>Check your email</h1>
    <p>We sent a link to @if ($email)<b>{{ $email }}</b>@else your email address @endif. Open it to confirm the address and continue.</p>
  </header>

  @if (session('status') === 'verification-link-sent')
    @include('pages.auth.partials.alert', ['tone' => 'info', 'message' => 'A new link is on its way.'])
  @endif

  <form class="stack" method="post" action="{{ route('verification.send') }}" data-busy-form>
    @csrf
    <button class="btn-primary" type="submit"><span class="btn-label">Send the link again</span><span class="spinner" hidden aria-hidden="true"></span></button>
  </form>

  <form method="post" action="{{ route('logout') }}" class="switch">
    @csrf
    Wrong account? <button class="link" type="submit">Sign out</button>
  </form>
</x-layouts::auth-split>
