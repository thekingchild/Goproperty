{{--
    Settings → Online payments (Modules\OnlinePayments\Livewire\PaystackSettingsPage,
    ONBOARDING_COMPLIANCE.md § 4.2): the organization's own Paystack account.
    Keys shown masked only; org-owners only; never shown to impersonating staff.
--}}
<x-page :heading="__('Settings')" :description="__('Your organization\'s details, how tenants pay you, and how your documents look.')">
    <x-workspace.settings-nav current="workspace.settings.payments.paystack" />

    @if ($setupUrl)
        <flux:callout icon="arrow-left" data-test="back-to-setup">
            <flux:callout.text><a href="{{ $setupUrl }}" class="font-medium underline">{{ __('Back to setting up your organization') }}</a></flux:callout.text>
        </flux:callout>
    @endif

    @if ($status)
        <flux:callout variant="success" icon="check-circle" :heading="$status" data-test="status" />
    @endif

    @error('connection')
        <flux:callout variant="danger" icon="exclamation-triangle" :heading="$message" data-test="refusal" />
    @enderror

    <section class="rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900" data-test="paystack-connection">
        <flux:heading size="lg" level="2">{{ __('Your own Paystack account') }}</flux:heading>
        <flux:text class="mt-1">{{ __('Tenants pay by card, bank transfer or USSD on their bill\'s page, and the money settles into your own Paystack account. :brand never holds it: it checks each payment with Paystack and records it, with a receipt.', ['brand' => $brand]) }}</flux:text>

        @if ($connection)
            <dl class="mt-4 grid gap-3 text-sm sm:grid-cols-2">
                <div>
                    <dt class="text-zinc-500 dark:text-zinc-400">{{ __('Status') }}</dt>
                    <dd class="mt-1 flex flex-wrap items-center gap-2" data-test="connection-status">
                        <flux:badge size="sm" :color="$connection->active() ? 'green' : ($connection->status->value === 'failing' ? 'red' : 'amber')">{{ __($connection->status->label()) }}</flux:badge>
                        @if ($connection->mode)
                            <flux:badge size="sm" :color="$connection->mode->value === 'live' ? 'blue' : 'zinc'">{{ __($connection->mode->label()) }}</flux:badge>
                        @endif
                    </dd>
                </div>
                <div>
                    <dt class="text-zinc-500 dark:text-zinc-400">{{ __('Last tested') }}</dt>
                    <dd class="mt-1">{{ $connection->last_tested_at?->timezone('Africa/Lagos')->format('d/m/Y H:i') ?? __('Not yet') }}@if ($connection->last_test_error) · <span class="text-red-600 dark:text-red-400">{{ $connection->last_test_error }}</span>@endif</dd>
                </div>
                <div>
                    <dt class="text-zinc-500 dark:text-zinc-400">{{ __('Public key') }}</dt>
                    <dd class="mt-1 font-mono" data-test="public-key">{{ $connection->maskedPublicKey() }}</dd>
                </div>
                <div>
                    <dt class="text-zinc-500 dark:text-zinc-400">{{ __('Secret key') }}</dt>
                    <dd class="mt-1 font-mono" data-test="secret-key">{{ $connection->maskedSecretKey() }}</dd>
                </div>
                <div class="sm:col-span-2">
                    <dt class="text-zinc-500 dark:text-zinc-400">{{ __('Webhook address') }}</dt>
                    <dd class="mt-1 break-all font-mono" data-test="webhook-url">{{ $webhookUrl }}</dd>
                    <dd class="mt-1 text-zinc-600 dark:text-zinc-400">{{ __('Paste it into your Paystack dashboard: Settings → API Keys & Webhooks → :mode Webhook URL. A Paystack account has one webhook address; if yours is already used for something else, use a separate Paystack account here.', ['mode' => $connection->mode?->value === 'test' ? __('Test') : __('Live')]) }}</dd>
                </div>
                <div class="sm:col-span-2">
                    <dt class="text-zinc-500 dark:text-zinc-400">{{ __('Last call from Paystack') }}</dt>
                    <dd class="mt-1">{{ $connection->last_event_at?->timezone('Africa/Lagos')->format('d/m/Y H:i') ?? __('None yet') }}</dd>
                </div>
            </dl>

            @if ($canManage)
                <div class="mt-5 flex flex-wrap gap-2">
                    <flux:button size="sm" wire:click="test" data-test="test-connection">{{ __('Test connection') }}</flux:button>
                    <flux:button size="sm" wire:click="rotate" wire:confirm="{{ __('Make a new webhook address? Paystack\'s calls to the current one will be refused until you paste the new one into your Paystack dashboard.') }}" data-test="rotate-webhook">{{ __('New webhook address') }}</flux:button>
                    <flux:button size="sm" variant="danger" wire:click="disconnect" wire:confirm="{{ __('Disconnect Paystack? Your keys are removed and tenants see bank transfer details only.') }}" data-test="disconnect">{{ __('Disconnect') }}</flux:button>
                </div>
            @endif
        @else
            <flux:text class="mt-4" data-test="not-connected">{{ __('Not connected. Tenants see bank transfer details only.') }}</flux:text>
        @endif
    </section>

    @if ($canManage)
        <section class="rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900" data-test="keys-form">
            <flux:heading size="lg" level="2">{{ $connection ? __('Replace your keys') : __('Connect Paystack') }}</flux:heading>
            <flux:text class="mt-1">{{ __('Copy both keys from your Paystack dashboard (Settings → API Keys & Webhooks). We try the secret key with Paystack before saving it, store it encrypted and never show it again.') }}</flux:text>

            <form wire:submit="connect" class="mt-5 grid gap-5 sm:grid-cols-2">
                <flux:input wire:model="publicKey" :label="__('Public key')" placeholder="pk_live_…" autocomplete="off" maxlength="150" required />
                <flux:input type="password" wire:model="secretKey" :label="__('Secret key')" placeholder="sk_live_…" autocomplete="off" maxlength="150" required />
                <div class="sm:col-span-2 flex gap-2">
                    <flux:button type="submit" variant="primary">{{ $connection ? __('Check and replace keys') : __('Check and connect') }}</flux:button>
                </div>
            </form>
        </section>
    @endif

    <section class="rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900" data-test="online-cap">
        <flux:heading size="lg" level="2">{{ __('Online payment limit') }}</flux:heading>
        <flux:text class="mt-1">{{ __('Bills owing more than this show bank transfer details only, which keeps large rent off the card gateway. Now: :amount.', ['amount' => $capNow->format()]) }}</flux:text>

        @if ($canManage)
            <form wire:submit="saveCap" class="mt-4 flex flex-col gap-3 sm:flex-row sm:items-end">
                <flux:input wire:model="cap" :label="__('Largest bill payable online (₦)')" inputmode="decimal" maxlength="20" :placeholder="__(':amount (standard)', ['amount' => str_replace('₦', '', $defaultCap->format())])" />
                <flux:button type="submit">{{ __('Save limit') }}</flux:button>
            </form>
            @error('cap')
                <flux:text class="mt-2 text-red-600 dark:text-red-400" data-test="cap-error">{{ $message }}</flux:text>
            @enderror
        @endif
    </section>

    @if ($held->isNotEmpty())
        <section class="rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900" data-test="held-payments">
            <flux:heading size="lg" level="2">{{ __('Online payments held for review') }}</flux:heading>
            <flux:text class="mt-1">{{ __('Paid through Paystack, but not as asked, so not confirmed automatically. Those recorded wait in the payments to confirm.') }}</flux:text>
            <ul class="mt-4 divide-y divide-zinc-200 text-sm dark:divide-zinc-700">
                @foreach ($held as $transaction)
                    <li class="py-3" wire:key="held-{{ $transaction->id }}" data-held="{{ $transaction->reference }}">
                        <span class="font-mono">{{ $transaction->reference }}</span> ·
                        @if ($transaction->paid_currency && $transaction->paid_currency !== 'NGN')
                            {{ $transaction->paid_amount_kobo?->toDecimalString() ?? '—' }} {{ $transaction->paid_currency }} ·
                        @else
                            {{ $transaction->paid_amount_kobo?->format() ?? '—' }} ·
                        @endif
                        {{ $transaction->paid_at?->timezone('Africa/Lagos')->format('d/m/Y') }}
                        <span class="block text-zinc-600 dark:text-zinc-400">{{ $transaction->hold_reason }}</span>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif
</x-page>
