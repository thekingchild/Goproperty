{{--
    "Owner portal" on an owner's page (Modules\Properties\Livewire\OwnerPortalAccess,
    P03-15). A link made for copy / QR shows once; only its hash is kept.
--}}
@php
    use App\Enums\InvitationChannel;
    use App\Enums\InvitationStatus;

    $date = fn ($at) => $at?->timezone('Africa/Lagos')->format('j M Y, g:i a');
@endphp
<section class="rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900" data-test="owner-portal-access">
    <style>
        @media print {
            body * { visibility: hidden !important; }
            [data-print-area], [data-print-area] * { visibility: visible !important; }
            [data-print-area] { position: absolute; inset: 0 auto auto 0; width: 100%; }
            [data-print-hide] { display: none !important; }
        }
    </style>

    <flux:heading size="lg" level="2">{{ __('Owner portal') }}</flux:heading>
    <flux:text class="mt-1">{{ __('Owners follow their buildings in the owner portal: units, occupancy, leases (tenants\' names only) and bills, read-only. No SMS codes: the invitation goes by email, by your WhatsApp, or as a link or QR code you share.') }}</flux:text>

    @if ($notice)
        <flux:callout class="mt-4" variant="success" icon="check-circle" :heading="$notice" role="status" data-test="owner-portal-notice" />
    @endif
    @if ($problem)
        <flux:callout class="mt-4" variant="danger" icon="exclamation-triangle" :heading="$problem" role="alert" data-test="owner-portal-problem" />
    @endif

    @if ($shareUrl)
        <div class="mt-4 rounded-lg border border-zinc-200 p-4 dark:border-zinc-700" data-print-area data-test="owner-portal-share">
            <p class="font-medium text-zinc-900 dark:text-white">{{ __(':organization invites :name to the owner portal', ['organization' => $organization->name, 'name' => $owner->display_name]) }}</p>
            <div class="mt-3 flex flex-col gap-4 sm:flex-row sm:items-start">
                <img src="{{ $qr }}" alt="{{ __('QR code of the link') }}" width="184" height="184" class="rounded border border-zinc-200 bg-white" data-test="owner-portal-qr">
                <div class="min-w-0 flex-1 space-y-3">
                    <flux:text>{{ __('Scan the code or open the link to set up your account. It works once, for 14 days.') }}</flux:text>
                    <input type="text" readonly value="{{ $shareUrl }}" class="w-full rounded-md border border-zinc-300 bg-zinc-50 px-3 py-2 font-mono text-xs dark:border-zinc-600 dark:bg-zinc-800" aria-label="{{ __('Link') }}" data-test="owner-portal-link" onfocus="this.select()">
                    <div class="flex flex-wrap gap-2" data-print-hide>
                        <flux:button size="sm" icon="clipboard" x-data x-on:click="navigator.clipboard?.writeText(@js($shareUrl)); $el.querySelector('[data-label]').textContent = @js(__('Copied'))"><span data-label>{{ __('Copy link') }}</span></flux:button>
                        <flux:button size="sm" icon="printer" x-data x-on:click="window.print()">{{ __('Print') }}</flux:button>
                        <flux:button size="sm" variant="ghost" wire:click="closeShare">{{ __('Done') }}</flux:button>
                    </div>
                </div>
            </div>
        </div>
    @endif

    <div class="mt-4 space-y-4">
        @if ($account)
            <div data-test="owner-portal-account">
                <flux:text>{{ __('Follows these buildings in the owner portal; signs in with :login.', ['login' => $account->email ?? $account->phone ?? __('a passkey')]) }}</flux:text>
                @if ($canManage)
                    <div class="mt-2">
                        <flux:button size="sm" variant="ghost" icon="user-minus" wire:click="removeAccess" wire:confirm="{{ __('Remove :name\'s access to the owner portal? They stop seeing these buildings at once. You can invite them again.', ['name' => $owner->display_name]) }}" data-test="remove-owner-access">{{ __('Remove access') }}</flux:button>
                    </div>
                @endif
            </div>
        @elseif ($owner->trashed())
            <flux:text>{{ __('Archived owners cannot be invited.') }}</flux:text>
        @elseif ($invitation)
            <div data-test="owner-portal-invitation">
                @if ($invitation->displayStatus() === InvitationStatus::Expired)
                    <flux:text>{{ __('The invitation (:channel) expired on :date.', ['channel' => $invitation->channel->label(), 'date' => $date($invitation->expires_at)]) }}</flux:text>
                @else
                    <flux:text>{{ __('Invited by :channel on :sent; the link works until :expires.', ['channel' => $invitation->channel->label(), 'sent' => $date($invitation->sent_at), 'expires' => $date($invitation->expires_at)]) }}</flux:text>
                @endif
                @if ($canManage)
                    <div class="mt-2 flex flex-wrap gap-2">
                        @foreach ($invitationChannels as $channel)
                            <flux:button size="sm" wire:click="resend('{{ $channel->value }}')" data-test="owner-resend-{{ $channel->value }}">{{ match ($channel) { InvitationChannel::Email => __('Send again by email'), InvitationChannel::WhatsApp => __('Send again by WhatsApp'), InvitationChannel::Link => __('New link and QR code') } }}</flux:button>
                        @endforeach
                        <flux:button size="sm" variant="ghost" wire:click="revoke" wire:confirm="{{ __('Revoke the invitation? Its link stops working at once.') }}" data-test="owner-revoke-invitation">{{ __('Revoke') }}</flux:button>
                    </div>
                @endif
            </div>
        @else
            <flux:text>{{ __('Not invited yet.') }}</flux:text>
            @if ($canManage)
                <div class="flex flex-wrap gap-2">
                    @foreach ($invitationChannels as $channel)
                        <flux:button size="sm" :variant="$loop->first ? 'primary' : 'filled'" wire:click="invite('{{ $channel->value }}')" data-test="owner-invite-{{ $channel->value }}">{{ match ($channel) { InvitationChannel::Email => __('Invite by email'), InvitationChannel::WhatsApp => __('Invite by WhatsApp'), InvitationChannel::Link => __('Show link and QR code') } }}</flux:button>
                    @endforeach
                </div>
                @if ($invitationChannels === [])
                    <flux:text class="text-sm">{{ __('Add the owner\'s email address or phone number to invite them.') }}</flux:text>
                @elseif ($owner->email === null)
                    <flux:text class="text-sm">{{ __('Add an email address to the owner to invite them by email.') }}</flux:text>
                @endif
            @endif
        @endif
    </div>
</section>
