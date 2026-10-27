{{--
    "Portal access" on a tenant's record (Modules\Tenants\Livewire\TenantPortalAccess,
    P03-01b). A link made for copy / QR shows once; only its hash is kept.
--}}
@php
    use App\Enums\InvitationChannel;
    use App\Enums\InvitationStatus;

    $date = fn ($at) => $at?->timezone('Africa/Lagos')->format('j M Y, g:i a');
    $phoneFree = $account !== null && $account->phone !== null && $account->phone === $tenant->phone;
@endphp
<section class="rounded-xl border border-zinc-200 bg-white p-5 lg:col-span-3 dark:border-zinc-700 dark:bg-zinc-900" data-test="tenant-portal-access">
    <style>
        @media print {
            body * { visibility: hidden !important; }
            [data-print-area], [data-print-area] * { visibility: visible !important; }
            [data-print-area] { position: absolute; inset: 0 auto auto 0; width: 100%; }
            [data-print-hide] { display: none !important; }
        }
    </style>

    <flux:heading size="lg" level="2">{{ __('Portal access') }}</flux:heading>
    <flux:text class="mt-1">{{ __('Tenants see their rent, bills and receipts in the tenant portal. No SMS codes: the invitation goes by email, by your WhatsApp, or as a link or QR code you share.') }}</flux:text>

    @if ($notice)
        <flux:callout class="mt-4" variant="success" icon="check-circle" :heading="$notice" role="status" data-test="portal-notice" />
    @endif
    @if ($problem)
        <flux:callout class="mt-4" variant="danger" icon="exclamation-triangle" :heading="$problem" role="alert" data-test="portal-problem" />
    @endif

    @if ($shareUrl)
        <div class="mt-4 rounded-lg border border-zinc-200 p-4 dark:border-zinc-700" data-print-area data-test="portal-share">
            <p class="font-medium text-zinc-900 dark:text-white">
                {{ $shareKind === 'sign-in' ? __('One-time sign-in link for :name', ['name' => $tenant->fullName()]) : __(':organization invites :name to the tenant portal', ['organization' => $organization->name, 'name' => $tenant->fullName()]) }}
            </p>
            <div class="mt-3 flex flex-col gap-4 sm:flex-row sm:items-start">
                <img src="{{ $qr }}" alt="{{ __('QR code of the link') }}" width="184" height="184" class="rounded border border-zinc-200 bg-white" data-test="portal-qr">
                <div class="min-w-0 flex-1 space-y-3">
                    <flux:text>{{ $shareKind === 'sign-in' ? __('Scan the code or open the link to sign in. It works once, for 24 hours. Anyone with it can sign in to this account, so give it only to the tenant.') : __('Scan the code or open the link to set up your account. It works once, for 14 days.') }}</flux:text>
                    <input type="text" readonly value="{{ $shareUrl }}" class="w-full rounded-md border border-zinc-300 bg-zinc-50 px-3 py-2 font-mono text-xs dark:border-zinc-600 dark:bg-zinc-800" aria-label="{{ __('Link') }}" data-test="portal-link" onfocus="this.select()">
                    <div class="flex flex-wrap gap-2" data-print-hide>
                        <flux:button size="sm" icon="clipboard" x-data x-on:click="navigator.clipboard?.writeText(@js($shareUrl)); $el.querySelector('[data-label]').textContent = @js(__('Copied'))"><span data-label>{{ __('Copy link') }}</span></flux:button>
                        <flux:button size="sm" icon="printer" x-data x-on:click="window.print()">{{ __('Print') }}</flux:button>
                        <flux:button size="sm" variant="ghost" wire:click="closeShare" data-test="portal-share-done">{{ __('Done') }}</flux:button>
                    </div>
                </div>
            </div>
        </div>
    @endif

    <div class="mt-4 space-y-4">
        @if ($account)
            <dl class="grid gap-4 sm:grid-cols-2" data-test="portal-account">
                <div>
                    <dt class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Portal account') }}</dt>
                    <dd class="text-zinc-900 dark:text-white">{{ __('Signs in with :login', ['login' => $account->email ?? $account->phone ?? __('a passkey')]) }}</dd>
                </div>
                <div>
                    <dt class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Phone number') }}</dt>
                    <dd class="text-zinc-900 dark:text-white" data-test="portal-phone-status">
                        @if ($account->phone_verified_at)
                            <flux:badge size="sm" color="green">{{ __($account->phone_verification_method?->label() ?? 'Verified') }}</flux:badge>
                            <span class="text-sm text-zinc-500">{{ $confirmedBy ? __('by :name, :date', ['name' => $confirmedBy->name, 'date' => $date($account->phone_verified_at)]) : $date($account->phone_verified_at) }}</span>
                        @else
                            <flux:badge size="sm" color="zinc">{{ __('Not verified') }}</flux:badge>
                        @endif
                    </dd>
                </div>
            </dl>

            @if ($canManage && ! $account->phone_verified_at && $phoneFree)
                <div>
                    <flux:button size="sm" icon="check-badge" wire:click="confirmPhone" wire:confirm="{{ __('Confirm that you checked :phone with :name in person? Your name is recorded.', ['phone' => $tenant->phone, 'name' => $tenant->fullName()]) }}" data-test="confirm-phone">{{ __('Phone number confirmed in person') }}</flux:button>
                </div>
            @endif

            <div class="border-t border-zinc-200 pt-4 dark:border-zinc-700" data-test="portal-sign-in-link">
                <flux:heading size="sm" level="3">{{ __('One-time sign-in link') }}</flux:heading>
                @if ($signInLink)
                    <flux:text class="mt-1">{{ __('A sign-in link made by :name works until :date (:channel).', ['name' => $signInLink->invitedBy?->name ?? __('your team'), 'date' => $date($signInLink->expires_at), 'channel' => $signInLink->channel->label()]) }}</flux:text>
                @endif
                @if ($signInRefusal)
                    <flux:text class="mt-1" data-test="sign-in-link-refusal">{{ $signInRefusal }}</flux:text>
                @elseif ($canManage)
                    <flux:text class="mt-1">{{ __('For a tenant who cannot sign in: the link signs them in once, within 24 hours, and lets them choose a new password.') }}</flux:text>
                    <div class="mt-2 flex flex-wrap gap-2">
                        @foreach ($signInChannels as $channel)
                            <flux:button size="sm" wire:click="createSignInLink('{{ $channel->value }}')" data-test="sign-in-link-{{ $channel->value }}">{{ $channel === InvitationChannel::WhatsApp ? __('Send by WhatsApp') : __('Show link and QR code') }}</flux:button>
                        @endforeach
                        @if ($signInLink)
                            <flux:button size="sm" variant="ghost" wire:click="revokeSignInLink" data-test="revoke-sign-in-link">{{ __('Revoke the link') }}</flux:button>
                        @endif
                    </div>
                @endif
            </div>
        @elseif ($tenant->trashed())
            <flux:text>{{ __('Archived tenants cannot be invited.') }}</flux:text>
        @elseif ($invitation)
            <div data-test="portal-invitation">
                @if ($invitation->displayStatus() === InvitationStatus::Expired)
                    <flux:text>{{ __('The invitation (:channel) expired on :date.', ['channel' => $invitation->channel->label(), 'date' => $date($invitation->expires_at)]) }}</flux:text>
                @else
                    <flux:text>{{ __('Invited by :channel on :sent; the link works until :expires.', ['channel' => $invitation->channel->label(), 'sent' => $date($invitation->sent_at), 'expires' => $date($invitation->expires_at)]) }}</flux:text>
                @endif
                @if ($canManage)
                    <div class="mt-2 flex flex-wrap gap-2">
                        @foreach ($invitationChannels as $channel)
                            <flux:button size="sm" wire:click="resend('{{ $channel->value }}')" data-test="resend-{{ $channel->value }}">{{ match ($channel) { InvitationChannel::Email => __('Send again by email'), InvitationChannel::WhatsApp => __('Send again by WhatsApp'), InvitationChannel::Link => __('New link and QR code') } }}</flux:button>
                        @endforeach
                        <flux:button size="sm" variant="ghost" wire:click="revoke" wire:confirm="{{ __('Revoke the invitation? Its link stops working at once.') }}" data-test="revoke-invitation">{{ __('Revoke') }}</flux:button>
                    </div>
                @endif
            </div>
        @else
            <flux:text>{{ __('Not invited yet.') }}</flux:text>
            @if ($canManage)
                <div class="flex flex-wrap gap-2">
                    @foreach ($invitationChannels as $channel)
                        <flux:button size="sm" :variant="$loop->first ? 'primary' : 'filled'" wire:click="invite('{{ $channel->value }}')" data-test="invite-{{ $channel->value }}">{{ match ($channel) { InvitationChannel::Email => __('Invite by email'), InvitationChannel::WhatsApp => __('Invite by WhatsApp'), InvitationChannel::Link => __('Show link and QR code') } }}</flux:button>
                    @endforeach
                </div>
                @if ($tenant->email === null)
                    <flux:text class="text-sm">{{ __('Add an email address to the tenant to invite them by email.') }}</flux:text>
                @endif
            @endif
        @endif
    </div>
</section>
