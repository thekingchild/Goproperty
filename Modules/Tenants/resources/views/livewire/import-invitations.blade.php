{{--
    "Invite tenants now" on a committed import's report (Modules\Tenants\Livewire\ImportInvitations,
    P03-13a, DATA_IMPORT.md § 2.6). Nothing is sent until the agency presses a button;
    the printable sheet's links show once (only their hashes are kept).
--}}
<div>
@if ($total > 0 || $sheet !== [])
<div class="flex flex-col gap-3 border-t border-zinc-100 pt-4 dark:border-zinc-800" data-test="import-invitations">
    <style>
        @media print {
            body * { visibility: hidden !important; }
            [data-print-area], [data-print-area] * { visibility: visible !important; }
            [data-print-area] { position: absolute; inset: 0 auto auto 0; width: 100%; }
            [data-print-hide] { display: none !important; }
        }
    </style>

    <div>
        <flux:heading level="3">{{ __('Invite tenants now') }}</flux:heading>
        <flux:text class="mt-1 text-sm">
            @if ($waiting === 0)
                {{ __('Every tenant of this import without portal access has an invitation. Send one again from the tenant\'s page.') }}
            @else
                {{ trans_choice('{1} The import contacted nobody. :count tenant has no portal access or invitation yet: invite them when you are ready.|[2,*] The import contacted nobody. :count tenants have no portal access or invitation yet: invite them when you are ready.', $waiting) }}
            @endif
        </flux:text>
    </div>

    @if ($notice)
        <flux:callout variant="success" icon="check-circle" :heading="$notice" role="status" data-test="invitations-notice" />
    @endif
    @if ($problem)
        <flux:callout variant="warning" icon="exclamation-triangle" :heading="$problem" role="alert" data-test="invitations-problem" />
    @endif

    @if ($canInvite)
        <div class="flex flex-wrap items-center gap-2" data-print-hide>
            @if ($withEmail > 0)
                <flux:button size="sm" variant="primary" icon="envelope" wire:click="inviteByEmail" wire:confirm="{{ __('Email a portal invitation to :count tenants?', ['count' => $withEmail]) }}" data-test="invite-tenants-email">{{ __('Invite by email (:count)', ['count' => $withEmail]) }}</flux:button>
            @endif
            @if ($whatsApp)
                <flux:button size="sm" icon="chat-bubble-left-right" wire:click="inviteByWhatsApp" wire:confirm="{{ __('Send a portal invitation by your WhatsApp to :count tenants?', ['count' => $waiting]) }}" data-test="invite-tenants-whatsapp">{{ __('Invite by WhatsApp (:count)', ['count' => $waiting]) }}</flux:button>
            @endif
            <flux:button size="sm" icon="printer" wire:click="printSheet" data-test="invite-tenants-sheet">{{ __('Printable QR sheet (:count)', ['count' => $waiting]) }}</flux:button>
        </div>
    @endif

    @if ($sheet !== [])
        <div class="rounded-lg border border-zinc-200 p-4 dark:border-zinc-700" data-print-area data-test="invitation-sheet">
            <p class="font-medium text-zinc-900 dark:text-white">{{ __(':organization invites you to its tenant portal', ['organization' => $organization->name]) }}</p>
            <p class="text-sm text-zinc-600 dark:text-zinc-300">{{ __('Scan your code to set up your account: see your rent, bills and receipts. Each code works once, for :days days.', ['days' => \Modules\Tenants\Portal\TenantInvitations::VALID_DAYS]) }}</p>
            <div class="mt-3 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($sheet as $i => $entry)
                    <div class="flex items-center gap-3 rounded border border-zinc-200 p-3 dark:border-zinc-700" data-test="invitation-sheet-entry">
                        <img src="{{ $qr[$i] }}" alt="{{ __('QR code of :name\'s invitation', ['name' => $entry['name']]) }}" width="120" height="120" class="rounded border border-zinc-200 bg-white">
                        <div class="min-w-0">
                            <p class="font-medium text-zinc-900 dark:text-white">{{ $entry['name'] }}</p>
                            <p class="text-sm text-zinc-600 dark:text-zinc-300">{{ $entry['phone'] }}</p>
                        </div>
                    </div>
                @endforeach
            </div>
            <div class="mt-3 flex flex-wrap gap-2" data-print-hide>
                <flux:button size="sm" icon="printer" x-data x-on:click="window.print()">{{ __('Print') }}</flux:button>
                <flux:button size="sm" variant="ghost" wire:click="closeSheet" data-test="invitation-sheet-done">{{ __('Done') }}</flux:button>
            </div>
        </div>
    @endif
</div>
@endif
</div>
