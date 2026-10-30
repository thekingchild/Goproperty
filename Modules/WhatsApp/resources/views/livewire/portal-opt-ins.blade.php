{{-- WhatsApp messages from each agency the account rents from (Modules\WhatsApp\Livewire\PortalOptIns, Meta policy). --}}
<div data-test="portal-whatsapp">
    <flux:heading size="lg" level="3">{{ __('WhatsApp messages') }}</flux:heading>
    <flux:text class="mt-1">{{ __('Your agency can send rent reminders and notices from its own WhatsApp number instead of SMS. You can stop them at any time.') }}</flux:text>

    @if ($notice)
        <flux:text class="mt-2 text-green-700 dark:text-green-400" data-test="portal-whatsapp-notice">{{ $notice }}</flux:text>
    @endif
    @if ($refusal)
        <flux:text class="mt-2 text-red-600 dark:text-red-400" data-test="portal-whatsapp-refusal">{{ $refusal }}</flux:text>
    @endif

    <ul class="mt-4 space-y-3">
        @foreach ($agencies as $agency)
            <li class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between" wire:key="agency-{{ $agency['organization']->id }}" data-agency="{{ $agency['organization']->id }}">
                <span class="text-sm">
                    <span class="font-medium">{{ $agency['organization']->name }}</span>
                    <span class="block text-zinc-600 dark:text-zinc-400">
                        {{ $agency['optedIn'] ? __('On: messages to :phone.', ['phone' => $phone]) : __('Off.') }}
                    </span>
                </span>
                @if ($agency['optedIn'])
                    <flux:button size="sm" wire:click="optOut({{ $agency['organization']->id }})" data-test="portal-opt-out">{{ __('Stop WhatsApp messages') }}</flux:button>
                @elseif ($verified && ! $agency['readOnly'])
                    <flux:button size="sm" variant="primary" wire:click="optIn({{ $agency['organization']->id }})" data-test="portal-opt-in">{{ __('Get WhatsApp messages at :phone', ['phone' => $phone]) }}</flux:button>
                @endif
            </li>
        @endforeach
    </ul>

    @unless ($verified)
        <flux:text class="mt-3 text-sm" data-test="portal-whatsapp-unverified">{{ __('WhatsApp messages start once your phone number is verified: open a link your agency sends you by WhatsApp, or ask them to confirm your number in person.') }}</flux:text>
    @endunless
</div>
