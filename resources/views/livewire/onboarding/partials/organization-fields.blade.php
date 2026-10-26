{{-- Organization fields, shared by the organization step and "Back" to it (ONBOARDING_COMPLIANCE.md § 2 step 2). --}}
<fieldset class="field choices">
  <legend class="label">What best describes you?</legend>
  @foreach ($types as $type)
    <label @class(['choice', 'is-selected' => $form->type === $type->value])>
      <input type="radio" name="type" value="{{ $type->value }}" wire:model.live="form.type">
      <span class="choice__body">
        <strong>{{ $type->label() }}</strong>
        <span>{{ match ($type) {
            \App\Enums\OrganizationType::Agency => 'You manage properties for landlords.',
            \App\Enums\OrganizationType::PropertyOwner => 'You own the buildings and manage them yourself.',
            \App\Enums\OrganizationType::Hybrid => 'You manage for others and own buildings too.',
        } }}</span>
      </span>
    </label>
  @endforeach
  @error('form.type')<p class="hint hint--error">{{ $message }}</p>@enderror
</fieldset>

@if ($form->type === \App\Enums\OrganizationType::PropertyOwner->value)
  <div class="alert alert--info" role="status" data-test="platform-covered-note">
    <span>Owners who manage their own buildings don't pay for {{ $brand ?? 'the platform' }}: your subscription is covered.</span>
  </div>
@endif

<div class="field">
  <label for="org-name">{{ $form->type === \App\Enums\OrganizationType::PropertyOwner->value ? 'Your name or company name' : 'Agency name' }}</label>
  <input class="input input--plain" id="org-name" type="text" wire:model.live.blur="form.name" autocomplete="organization" maxlength="120" required @error('form.name') aria-invalid="true" @enderror>
  @error('form.name')<p class="hint hint--error">{{ $message }}</p>@enderror
</div>
