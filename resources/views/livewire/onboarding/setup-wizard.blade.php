{{-- Signup wizard inside the workspace (App\Livewire\Onboarding\SetupWizard), one step at a time. --}}
<x-onboarding.frame :current="$step">
  @if ($notice)
    @include('pages.auth.partials.alert', ['tone' => 'info', 'message' => $notice])
  @endif

  @switch($step)
    @case(\App\Enums\OnboardingStep::Organization)
      <header class="auth-heading">
        <h1>Your organization</h1>
        <p>Change anything you need. Your web address stays <b>{{ $host }}/o/{{ $organization->slug }}</b>.</p>
      </header>
      <form class="stack" wire:submit="saveOrganization" novalidate>
        @include('livewire.onboarding.partials.organization-fields')
        @include('livewire.onboarding.partials.place-fields')
        @include('livewire.onboarding.partials.actions', ['primary' => 'Continue', 'target' => 'saveOrganization', 'back' => false])
      </form>
      @break

    @case(\App\Enums\OnboardingStep::Tier)
      <header class="auth-heading">
        <h1>Choose your Transaction Tier</h1>
        <p>Your tier sets how much rent you can manage through {{ $brand }}. It's separate from your plan. Tenants always pay you directly.</p>
      </header>
      <form class="stack" wire:submit="saveTier" novalidate>
        <div class="field">
          <label for="annual-volume">Rent and charges you expect to collect in a year</label>
          <div class="prefixed">
            <span class="prefixed__text" aria-hidden="true">₦</span>
            <input class="input input--plain" id="annual-volume" type="text" inputmode="numeric" wire:model.live.debounce.500ms="annualVolume" placeholder="25,000,000" maxlength="24" aria-describedby="volume-hint" required @error('annualVolume') aria-invalid="true" @enderror>
          </div>
          <p class="hint" id="volume-hint">An estimate across all your properties is fine.</p>
          @error('annualVolume')<p class="hint hint--error">{{ $message }}</p>@enderror
        </div>

        <fieldset class="field choices">
          <legend class="label">Tier</legend>
          @foreach ($tierOptions as $option)
            <label @class(['choice', 'is-selected' => $tier === $option->tier->value])>
              <input type="radio" name="tier" value="{{ $option->tier->value }}" wire:model.live="tier">
              <span class="choice__body">
                <strong>{{ $option->tier->label() }} · {{ $option->name }}
                  @if ($recommendedTier === $option->tier)<span class="badge">Suggested</span>@endif
                </strong>
                <span>
                  @if ($option->annualLimit !== null)
                    Up to {{ $option->annualLimit->format() }} a year, {{ $option->singlePaymentLimit?->format() }} per payment.
                  @else
                    No yearly cap; single payments above ₦100 million are reviewed.
                  @endif
                </span>
                <span class="choice__meta">{{ $option->suits }} · {{ $option->activation }}</span>
              </span>
            </label>
          @endforeach
          @error('tier')<p class="hint hint--error">{{ $message }}</p>@enderror
        </fieldset>

        @if ($tier !== null && $tier > 1)
          <div class="alert alert--info" role="status" data-test="tier-review-note">
            <span><b>Tier {{ $tier }} needs a compliance review.</b> You'll be asked for documents such as your CAC registration, SCUML certificate and directors' IDs. Until it's approved you work at Tier 1 limits, so nothing stops you starting today.</span>
          </div>
        @endif

        @include('livewire.onboarding.partials.actions', ['primary' => 'Continue', 'target' => 'saveTier', 'back' => true])
      </form>
      @break

    @case(\App\Enums\OnboardingStep::Plan)
      <header class="auth-heading">
        <h1>Choose a plan</h1>
        <p>Your {{ $trialDays }}-day free trial starts now, with every feature of the plan you pick. No card needed.</p>
      </header>
      @if ($organization->platform_covered)
        <div class="alert alert--info" role="status" data-test="platform-covered-plan">
          <span>Your subscription is covered by {{ $brand }}: you won't be billed for it.</span>
        </div>
      @endif
      <form class="stack" wire:submit="savePlan" novalidate>
        <fieldset class="field choices">
          <legend class="sr-only">Plan</legend>
          @foreach ($plans as $option)
            <label @class(['choice', 'is-selected' => $plan === $option->key])>
              <input type="radio" name="plan" value="{{ $option->key }}" wire:model.live="plan">
              <span class="choice__body">
                <strong>{{ $option->name }}
                  @if ($option->featured)<span class="badge">Most popular</span>@endif
                  <span class="choice__price">{{ $option->custom ? 'from ' : '' }}{{ $option->base->format() }}<small>/month</small></span>
                </strong>
                <span>{{ $option->audience }}</span>
                <span class="choice__meta">{{ number_format($option->includedUnits) }} units included{{ $option->extraUnit !== null ? ' · '.$option->extraUnit->format().' per extra unit' : '' }}</span>
              </span>
            </label>
          @endforeach
          @error('plan')<p class="hint hint--error">{{ $message }}</p>@enderror
        </fieldset>
        <p class="hint">Prices before VAT. You can change plan at any time during the trial.</p>
        @include('livewire.onboarding.partials.actions', ['primary' => 'Start my free trial', 'target' => 'savePlan', 'back' => true])
      </form>
      @break

    @case(\App\Enums\OnboardingStep::Collections)
      <header class="auth-heading">
        <h1>How will tenants pay you?</h1>
        <p>Rent goes straight from tenants to you. {{ $brand }} never holds your money.</p>
      </header>
      <ul class="options-list">
        <li>
          <strong>Bank transfer</strong>
          <span>Add the bank account tenants pay into. Every bill gets a payment reference, and you match payments from your bank statement.</span>
        </li>
        <li>
          <strong>Your own Paystack account</strong>
          <span>Let tenants pay online by card, transfer or USSD into your Paystack account. Available from the Growth plan.</span>
        </li>
      </ul>
      <p class="hint">You can set these up from the checklist on your dashboard.</p>
      <form class="stack" wire:submit="setUpCollectionsLater">
        @include('livewire.onboarding.partials.actions', ['primary' => 'Set up later', 'target' => 'setUpCollectionsLater', 'back' => true])
      </form>
      @break

    @default
      <header class="auth-heading">
        <h1>Add your properties</h1>
        <p>Bring in what you already have, or start with one building.</p>
      </header>
      <ul class="options-list">
        <li>
          <strong>Import from Excel</strong>
          <span>Owners, buildings, units, tenants and running leases. You see a dry run first, and nothing is saved until you confirm.</span>
        </li>
        <li>
          <strong>Add your first property</strong>
          <span>Type in one building and its units, then add the rest when you're ready.</span>
        </li>
      </ul>
      <p class="hint">You can do either from the checklist on your dashboard.</p>
      <form class="stack" wire:submit="finish">
        @include('livewire.onboarding.partials.actions', ['primary' => 'Go to my dashboard', 'target' => 'finish', 'back' => true])
      </form>
  @endswitch
</x-onboarding.frame>
