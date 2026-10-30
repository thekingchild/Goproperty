{{--
    Settings → WhatsApp (Modules\WhatsApp\Livewire\WhatsAppSettingsPage,
    ARCHITECTURE.md § 6.7): the organization's own WhatsApp Business account
    and its templates. The access token is shown masked, the app secret never;
    the verify token only to org-owners.
--}}
<x-page :heading="__('Settings')" :description="__('Your organization\'s details, how tenants pay you, and how your documents look.')">
    <x-workspace.settings-nav current="workspace.settings.operations.whatsapp" />

    @if ($status)
        <flux:callout variant="success" icon="check-circle" :heading="$status" data-test="status" />
    @endif

    @error('connection')
        <flux:callout variant="danger" icon="exclamation-triangle" :heading="$message" data-test="refusal" />
    @enderror

    @unless ($planAllows)
        <flux:callout icon="information-circle" data-test="plan-notice">
            <flux:callout.text>{{ __('WhatsApp messages come with the Growth plan and above. On your plan, tenants hear from you by email, SMS and in the app.') }}</flux:callout.text>
        </flux:callout>
    @endunless

    <section class="rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900" data-test="whatsapp-connection">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <flux:heading size="lg" level="2">{{ __('Your own WhatsApp Business account') }}</flux:heading>
            <flux:button size="sm" :href="route('workspace.settings.operations.whatsapp.messages', ['organization' => request()->route('organization')])" wire:navigate data-test="messages-link">{{ __('Messages and templates') }}</flux:button>
        </div>
        <flux:text class="mt-1">{{ __('Reminders and notices go from your own WhatsApp number, using the message templates Meta approved for your account. Meta bills you for them directly; :brand does not resell WhatsApp messages. Tenants get WhatsApp only once they agree to it.', ['brand' => $brand]) }}</flux:text>

        @if ($connection)
            <dl class="mt-4 grid gap-3 text-sm sm:grid-cols-2">
                <div>
                    <dt class="text-zinc-500 dark:text-zinc-400">{{ __('Status') }}</dt>
                    <dd class="mt-1" data-test="connection-status">
                        <flux:badge size="sm" :color="$connection->active() ? 'green' : ($connection->status->value === 'failing' ? 'red' : 'amber')">{{ __($connection->status->label()) }}</flux:badge>
                    </dd>
                </div>
                <div>
                    <dt class="text-zinc-500 dark:text-zinc-400">{{ __('Last checked') }}</dt>
                    <dd class="mt-1">{{ $connection->last_tested_at?->timezone('Africa/Lagos')->format('d/m/Y H:i') ?? __('Not yet') }}@if ($connection->last_test_error) · <span class="text-red-600 dark:text-red-400">{{ $connection->last_test_error }}</span>@endif</dd>
                </div>
                <div>
                    <dt class="text-zinc-500 dark:text-zinc-400">{{ __('WhatsApp number') }}</dt>
                    <dd class="mt-1" data-test="display-number">{{ $connection->display_phone_number ?? '—' }}@if ($connection->verified_name) · {{ $connection->verified_name }}@endif</dd>
                </div>
                <div>
                    <dt class="text-zinc-500 dark:text-zinc-400">{{ __('Access token') }}</dt>
                    <dd class="mt-1 font-mono" data-test="access-token">{{ $canConnect ? $connection->maskedAccessToken() : __('Saved') }}</dd>
                </div>
                <div>
                    <dt class="text-zinc-500 dark:text-zinc-400">{{ __('WhatsApp Business Account ID') }}</dt>
                    <dd class="mt-1 font-mono">{{ $connection->waba_id }}</dd>
                </div>
                <div>
                    <dt class="text-zinc-500 dark:text-zinc-400">{{ __('Phone number ID') }}</dt>
                    <dd class="mt-1 font-mono">{{ $connection->phone_number_id }}</dd>
                </div>
                <div class="sm:col-span-2">
                    <dt class="text-zinc-500 dark:text-zinc-400">{{ __('Webhook callback URL') }}</dt>
                    <dd class="mt-1 break-all font-mono" data-test="webhook-url">{{ $webhookUrl }}</dd>
                    @if ($canConnect)
                        <dt class="mt-3 text-zinc-500 dark:text-zinc-400">{{ __('Verify token') }}</dt>
                        <dd class="mt-1 break-all font-mono" data-test="verify-token">{{ $connection->webhook_verify_token }}</dd>
                    @endif
                    <dd class="mt-1 text-zinc-600 dark:text-zinc-400">{{ __('In your Meta app: WhatsApp → Configuration → Webhook, paste the callback URL and the verify token, then subscribe to the "messages" field. Delivery and read receipts then show in the delivery log.') }}</dd>
                </div>
                <div>
                    <dt class="text-zinc-500 dark:text-zinc-400">{{ __('Last call from Meta') }}</dt>
                    <dd class="mt-1">{{ $connection->last_event_at?->timezone('Africa/Lagos')->format('d/m/Y H:i') ?? __('None yet') }}</dd>
                </div>
                <div>
                    <dt class="text-zinc-500 dark:text-zinc-400">{{ __('Templates synced') }}</dt>
                    <dd class="mt-1">{{ $connection->templates_synced_at?->timezone('Africa/Lagos')->format('d/m/Y H:i') ?? __('Not yet') }}@if ($connection->last_sync_error) · <span class="text-red-600 dark:text-red-400">{{ $connection->last_sync_error }}</span>@endif</dd>
                </div>
            </dl>

            @if ($canConnect)
                <div class="mt-5 flex flex-wrap gap-2">
                    <flux:button size="sm" wire:click="test" data-test="test-connection">{{ __('Check connection') }}</flux:button>
                    <flux:button size="sm" wire:click="newVerifyToken" wire:confirm="{{ __('Make a new verify token? Meta keeps using the webhook as it is; you need the new token only when you set the webhook up again.') }}" data-test="new-verify-token">{{ __('New verify token') }}</flux:button>
                    <flux:button size="sm" variant="danger" wire:click="disconnect" wire:confirm="{{ __('Disconnect WhatsApp? Your access token and app secret are removed and reminders go by SMS again.') }}" data-test="disconnect">{{ __('Disconnect') }}</flux:button>
                </div>
            @endif
        @else
            <flux:text class="mt-4" data-test="not-connected">{{ __('Not connected. Tenants hear from you by email, SMS and in the app.') }}</flux:text>
        @endif
    </section>

    @if ($canConnect && $planAllows)
        <section class="rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900" data-test="credentials-form">
            <flux:heading size="lg" level="2">{{ $connection ? __('Replace the connection') : __('Connect WhatsApp') }}</flux:heading>
            <flux:text class="mt-1">{{ __('From your Meta app (developers.facebook.com → your app → WhatsApp → API Setup) and Meta Business Settings (a system user\'s permanent token with whatsapp_business_messaging and whatsapp_business_management). We check them with Meta before saving, store the token and app secret encrypted and never show them again.') }}</flux:text>

            <form wire:submit="connect" class="mt-5 grid gap-5 sm:grid-cols-2">
                <flux:input wire:model="wabaId" :label="__('WhatsApp Business Account ID')" inputmode="numeric" autocomplete="off" maxlength="32" required />
                <flux:input wire:model="phoneNumberId" :label="__('Phone number ID')" inputmode="numeric" autocomplete="off" maxlength="32" required />
                <flux:input type="password" wire:model="accessToken" :label="__('Permanent access token')" :placeholder="$connection ? __('Leave empty to keep the saved one') : 'EAA…'" autocomplete="off" maxlength="1000" :required="! $connection" />
                <flux:input type="password" wire:model="appSecret" :label="__('App secret')" :placeholder="$connection ? __('Leave empty to keep the saved one') : ''" autocomplete="off" maxlength="64" :required="! $connection" />
                <div class="sm:col-span-2">
                    <flux:button type="submit" variant="primary">{{ $connection ? __('Check and replace') : __('Check and connect') }}</flux:button>
                </div>
            </form>
        </section>
    @endif

    @if ($connection)
        <section class="rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900" data-test="whatsapp-templates">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <flux:heading size="lg" level="2">{{ __('Templates') }}</flux:heading>
                @if ($canSync)
                    <flux:button size="sm" wire:click="sync" data-test="sync-templates">{{ __('Sync from Meta') }}</flux:button>
                @endif
            </div>
            <flux:text class="mt-1">{{ __('Fetched from your account every morning. Only approved utility templates can be used; one Meta pauses or rejects stops being sent at the next sync, and that message goes by its next channel.') }}</flux:text>

            @if ($templates->isEmpty())
                <flux:text class="mt-4" data-test="no-templates">{{ __('No templates yet. Create them in WhatsApp Manager, wait for Meta\'s approval, then sync.') }}</flux:text>
            @else
                <ul class="mt-4 divide-y divide-zinc-200 text-sm dark:divide-zinc-700">
                    @foreach ($templates as $template)
                        <li class="py-3" wire:key="template-{{ $template->id }}" data-template="{{ $template->name }}">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="font-mono font-medium">{{ $template->label() }}</span>
                                <flux:badge size="sm" :color="$template->status->color()">{{ __($template->status->label()) }}</flux:badge>
                                <flux:badge size="sm" :color="$template->category->value === 'utility' ? 'blue' : 'zinc'">{{ __($template->category->label()) }}</flux:badge>
                                <span class="text-zinc-500 dark:text-zinc-400">{{ trans_choice('{0} no variables|{1} :count variable|[2,*] :count variables', $template->variable_count, ['count' => $template->variable_count]) }}</span>
                            </div>
                            <p class="mt-1 whitespace-pre-line text-zinc-600 dark:text-zinc-400">{{ $template->layout()->body }}</p>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>
    @endif
</x-page>
