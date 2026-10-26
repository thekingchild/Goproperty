{{--
    After the signup form (ONBOARDING_COMPLIANCE.md § 2): the same page
    whether or not the address already had an account, so it reveals none.
    New addresses get the verification link; existing ones an email saying
    so (App\Onboarding\RegisterAccount).
--}}
<x-layouts::auth-split :title="__('Check your email')" :activity="false">
  <x-slot:top><span>Already confirmed?</span><a href="{{ route('login') }}">Sign in</a></x-slot:top>
  <x-slot:mobileLink><a class="auth-panel__mobile-link" href="{{ route('login') }}">Sign in</a></x-slot:mobileLink>
  <x-slot:panel>
    <p class="auth-panel__title">Almost <span>there</span></p>
    <x-onboarding.steps :current="\App\Enums\OnboardingStep::VerifyEmail" />
  </x-slot:panel>

  <header class="auth-heading">
    <span class="auth-heading__icon" aria-hidden="true"><svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/></svg></span>
    <p class="wizard-progress"><b>Step 2 of {{ \App\Enums\OnboardingStep::count() }}</b> · {{ \App\Enums\OnboardingStep::VerifyEmail->label() }}</p>
    <h1>Check your email</h1>
    <p>We've sent an email to @if ($email)<b>{{ $email }}</b>@else the address you gave @endif. Open the link in it to confirm your address and carry on setting up.</p>
  </header>

  <ul class="tips">
    <li>Open the link on this device and you'll go straight to the next step.</li>
    <li>Nothing there? Check your spam or promotions folder. The link works for 60 minutes.</li>
    <li>Need a new link? <a class="link" href="{{ route('login') }}">Sign in</a> and we'll send another.</li>
  </ul>

  <p class="switch">Wrong address? <a href="{{ route('register') }}">Start again</a></p>
</x-layouts::auth-split>
