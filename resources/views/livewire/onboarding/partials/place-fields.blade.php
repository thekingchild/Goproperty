<div class="field-row">
  <div class="field">
    <label for="org-state">State</label>
    <select class="input input--plain" id="org-state" wire:model="form.state" required @error('form.state') aria-invalid="true" @enderror>
      <option value="">Choose…</option>
      @foreach ($states as $state)
        <option value="{{ $state }}">{{ \App\Support\NigerianStates::label($state) }}</option>
      @endforeach
    </select>
    @error('form.state')<p class="hint hint--error">{{ $message }}</p>@enderror
  </div>
  <div class="field">
    <label for="org-lga">LGA <span class="optional">optional</span></label>
    <input class="input input--plain" id="org-lga" type="text" wire:model="form.lga" maxlength="64" @error('form.lga') aria-invalid="true" @enderror>
    @error('form.lga')<p class="hint hint--error">{{ $message }}</p>@enderror
  </div>
</div>

<div class="field">
  <label for="org-cac">CAC number <span class="optional">optional</span></label>
  <input class="input input--plain input--mono" id="org-cac" type="text" wire:model="form.cacNumber" placeholder="RC 1234567" maxlength="20" autocapitalize="characters" @error('form.cacNumber') aria-invalid="true" @enderror>
  <p class="hint">Not needed for Tier 1. Registered agencies add it for Tier 2 and 3.</p>
  @error('form.cacNumber')<p class="hint hint--error">{{ $message }}</p>@enderror
</div>
