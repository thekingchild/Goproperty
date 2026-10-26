{{--
    Signup progress (ONBOARDING_COMPLIANCE.md § 2): the seven steps with the
    current one marked, in the brand panel on desktop and as "Step 4 of 7"
    above the form on phones.
--}}
@props(['current'])
@php($steps = \App\Enums\OnboardingStep::cases())
<ol {{ $attributes->merge(['class' => 'wizard-steps']) }} aria-label="Signup steps">
  @foreach ($steps as $step)
    @php($done = $step->isBefore($current))
    <li @class(['is-done' => $done, 'is-current' => $step === $current]) @if ($step === $current) aria-current="step" @endif>
      <span class="wizard-steps__dot" aria-hidden="true">
        @if ($done)
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="m5 12 5 5L20 7"/></svg>
        @else
          {{ $step->number() }}
        @endif
      </span>
      <span>{{ $step->label() }}@if ($done)<span class="sr-only"> (done)</span>@endif</span>
    </li>
  @endforeach
</ol>
