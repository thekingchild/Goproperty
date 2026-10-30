{{-- WhatsApp consent on a tenant's record (Modules\WhatsApp\Livewire\TenantOptIn, Meta policy). --}}
<section class="rounded-xl border border-zinc-200 bg-white p-5 lg:col-span-3 dark:border-zinc-700 dark:bg-zinc-900" data-test="tenant-whatsapp">
    <flux:heading size="lg" level="2">{{ __('WhatsApp messages') }}</flux:heading>

    @if ($notice)
        <flux:text class="mt-2 text-green-700 dark:text-green-400" data-test="opt-in-notice">{{ $notice }}</flux:text>
    @endif

    @if ($current)
        <flux:text class="mt-2" data-test="opt-in-status">{{ __('Agreed to WhatsApp messages at :phone on :date, :how.', ['phone' => $latest->phone, 'date' => $latest->opted_in_at->timezone('Africa/Lagos')->format('d/m/Y'), 'how' => __($latest->source->label())]) }}</flux:text>
    @elseif ($latest !== null && $latest->open())
        <flux:text class="mt-2" data-test="opt-in-status">{{ __('Agreed at :phone, but that is no longer their number. Ask again before WhatsApp messages go to the new one.', ['phone' => $latest->phone]) }}</flux:text>
    @elseif ($latest !== null)
        <flux:text class="mt-2" data-test="opt-in-status">{{ __('Asked for no more WhatsApp messages on :date.', ['date' => $latest->opted_out_at?->timezone('Africa/Lagos')->format('d/m/Y')]) }}</flux:text>
    @else
        <flux:text class="mt-2" data-test="opt-in-status">{{ __('Has not agreed to WhatsApp messages yet. Reminders go by email and SMS as usual.') }}</flux:text>
    @endif

    @unless ($working)
        <flux:text class="mt-1 text-sm">{{ __('Your WhatsApp connection is not working now, so nothing goes by WhatsApp.') }}</flux:text>
    @endunless

    @if ($canRecord)
        @if ($current)
            <div class="mt-4">
                <flux:button size="sm" wire:click="optOut" wire:confirm="{{ __('Record that this tenant wants no more WhatsApp messages?') }}" data-test="record-opt-out">{{ __('Record: no more WhatsApp') }}</flux:button>
            </div>
        @elseif ($number !== null)
            <form wire:submit="optIn" class="mt-4 flex flex-col gap-3">
                <flux:checkbox wire:model="confirmed" :label="__('The tenant agreed, in person, to get rent reminders and notices by WhatsApp at :phone.', ['phone' => $number])" data-test="opt-in-confirm" />
                @error('confirmed')
                    <flux:text class="text-red-600 dark:text-red-400" data-test="opt-in-error">{{ $message }}</flux:text>
                @enderror
                <div>
                    <flux:button size="sm" type="submit" data-test="record-opt-in">{{ __('Record consent') }}</flux:button>
                </div>
            </form>
        @else
            <flux:text class="mt-3 text-sm">{{ __('Add the tenant\'s phone number to record their consent.') }}</flux:text>
        @endif
    @endif
</section>
