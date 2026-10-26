<div class="wizard-actions">
  @if ($back)
    <button class="btn-secondary" type="button" wire:click="back" wire:loading.attr="disabled">Back</button>
  @endif
  <button class="btn-primary" type="submit" wire:loading.attr="aria-busy" wire:target="{{ $target }}"><span class="btn-label">{{ $primary }}</span><span class="spinner" wire:loading wire:target="{{ $target }}" aria-hidden="true"></span></button>
</div>
