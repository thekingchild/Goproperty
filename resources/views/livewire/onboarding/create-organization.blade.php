{{-- Signup, organization step (App\Livewire\Onboarding\CreateOrganization). --}}
<x-onboarding.frame :current="$step">
  <header class="auth-heading">
    <h1>Tell us about your organization</h1>
    <p>This becomes your workspace. You can change the details later in Settings.</p>
  </header>

  <form class="stack" wire:submit="save" novalidate>
    @include('livewire.onboarding.partials.organization-fields')

    <div class="field">
      <label for="org-slug">Web address</label>
      <div class="prefixed">
        <span class="prefixed__text" aria-hidden="true">{{ $host }}/o/</span>
        <input class="input input--plain" id="org-slug" type="text" wire:model.live.blur="slug" maxlength="{{ \App\Onboarding\OrganizationSlug::MAX }}" autocapitalize="none" spellcheck="false" aria-describedby="slug-hint" required @error('slug') aria-invalid="true" @enderror>
      </div>
      <p class="hint" id="slug-hint">Links in your emails and receipts use it. Lower-case letters, numbers and hyphens.</p>
      @error('slug')<p class="hint hint--error">{{ $message }}</p>@enderror
    </div>

    @include('livewire.onboarding.partials.place-fields')

    @if ($needsPhone)
      <div class="field">
        <label for="org-phone">Your mobile number</label>
        <input class="input input--plain" id="org-phone" type="tel" inputmode="tel" wire:model="phone" autocomplete="tel" placeholder="0803 123 4567" maxlength="32" required @error('phone') aria-invalid="true" @enderror>
        <p class="hint">We couldn't keep the number you gave at signup. We never send SMS codes.</p>
        @error('phone')<p class="hint hint--error">{{ $message }}</p>@enderror
      </div>
    @endif

    <div class="wizard-actions">
      <button class="btn-primary" type="submit" wire:loading.attr="aria-busy" wire:target="save"><span class="btn-label">Continue</span><span class="spinner" wire:loading wire:target="save" aria-hidden="true"></span></button>
    </div>
  </form>
</x-onboarding.frame>
