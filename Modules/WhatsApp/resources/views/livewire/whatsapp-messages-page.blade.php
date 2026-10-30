{{--
    Settings → WhatsApp → Messages and templates (Modules\WhatsApp\Livewire\WhatsAppMessagesPage,
    ARCHITECTURE.md § 6.7): which approved template each message uses, what fills its
    variables, and the order of WhatsApp and SMS.
--}}
<x-page :heading="__('Settings')" :description="__('Your organization\'s details, how tenants pay you, and how your documents look.')">
    <x-workspace.settings-nav current="workspace.settings.operations.whatsapp" />

    <div>
        <flux:button size="sm" icon="arrow-left" :href="route('workspace.settings.operations.whatsapp', ['organization' => request()->route('organization')])" wire:navigate>{{ __('WhatsApp connection') }}</flux:button>
    </div>

    @if ($status)
        <flux:callout variant="success" icon="check-circle" :heading="$status" data-test="status" />
    @endif

    @if ($definition)
        <section class="rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900" data-test="mapping-editor" data-event="{{ $definition->key }}">
            <flux:heading size="lg" level="2">{{ $definition->label }}</flux:heading>
            <flux:text class="mt-1">{{ __('Choose one of your approved utility templates and what fills each of its variables. Only this message\'s own values can be used.') }}</flux:text>

            <form wire:submit="save" class="mt-5 grid gap-5">
                <flux:select wire:model.live="templateId" :label="__('Template')" data-test="template-select">
                    <flux:select.option value="">{{ __('No template (WhatsApp off)') }}</flux:select.option>
                    @foreach ($templates as $option)
                        <flux:select.option :value="$option->id">{{ $option->label() }}</flux:select.option>
                    @endforeach
                </flux:select>
                @error('templateId')
                    <flux:text class="text-red-600 dark:text-red-400" data-test="template-error">{{ $message }}</flux:text>
                @enderror

                @foreach ($variables as $index => $variable)
                    <flux:select wire:model.live="placeholders.{{ $index }}" :label="$template->layout()->describe($variable)" wire:key="variable-{{ $templateId }}-{{ $index }}" data-variable="{{ $variable }}">
                        <flux:select.option value="">{{ __('Choose a value') }}</flux:select.option>
                        @foreach ($definition->placeholders as $placeholder)
                            <flux:select.option :value="$placeholder->name">{{ $placeholder->name }} — {{ $placeholder->description }}</flux:select.option>
                        @endforeach
                    </flux:select>
                @endforeach
                @error('placeholders')
                    <flux:text class="text-red-600 dark:text-red-400" data-test="placeholders-error">{{ $message }}</flux:text>
                @enderror

                <flux:select wire:model="channelOrder" :label="__('Channel order')" data-test="channel-order">
                    @foreach ($orders as $value => $label)
                        <flux:select.option :value="$value">{{ $label }}</flux:select.option>
                    @endforeach
                </flux:select>
                @if ($definition->fallsBackToSms && ! $definition->smsRung)
                    <flux:text class="text-sm">{{ __('No SMS goes on this day of the reminder, so there is nothing after WhatsApp. Email still goes as usual.') }}</flux:text>
                @else
                    <flux:text class="text-sm">{{ __('Email and in-app reminders go as usual whatever you choose.') }}</flux:text>
                @endif
                @error('channelOrder')
                    <flux:text class="text-red-600 dark:text-red-400">{{ $message }}</flux:text>
                @enderror

                <flux:switch wire:model="active" :label="__('Send this message by WhatsApp')" data-test="mapping-active" />

                @if ($preview !== null)
                    <div data-test="preview">
                        <flux:heading size="sm" level="3">{{ __('Preview with sample values') }}</flux:heading>
                        <p class="mt-2 whitespace-pre-line rounded-lg bg-green-50 p-4 text-sm text-zinc-900 dark:bg-green-950 dark:text-zinc-100">{{ $preview }}</p>
                    </div>
                @endif

                <div class="flex flex-wrap gap-2">
                    <flux:button type="submit" variant="primary" data-test="save-mapping">{{ __('Save') }}</flux:button>
                    @if ($hasSiblings)
                        <flux:button type="button" wire:click="copyToRungs" data-test="copy-to-rungs">{{ __('Save and use for every day of this reminder') }}</flux:button>
                    @endif
                    <flux:button type="button" variant="ghost" wire:click="cancel">{{ __('Cancel') }}</flux:button>
                </div>
            </form>
        </section>
    @endif

    @foreach ($groups as $group => $rows)
        <section class="rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900" data-test="message-group">
            <flux:heading size="lg" level="2">{{ __($group) }}</flux:heading>
            <ul class="mt-3 divide-y divide-zinc-200 text-sm dark:divide-zinc-700">
                @foreach ($rows as $row)
                    @php($mapping = $row['mapping'])
                    <li class="flex flex-col gap-2 py-3 sm:flex-row sm:items-center sm:justify-between" wire:key="event-{{ $row['event']->key }}" data-message="{{ $row['event']->key }}">
                        <span class="min-w-0">
                            <span class="font-medium text-zinc-900 dark:text-white">{{ $row['event']->label }}</span>
                            <span class="block text-zinc-500 dark:text-zinc-400">
                                @if ($mapping?->template)
                                    {{ $mapping->template->label() }} ·
                                @endif
                                {{ $mapping ? implode(' → ', \Modules\WhatsApp\Livewire\WhatsAppMessagesPage::describeOrder($mapping->channel_order)) : __('Not set up for WhatsApp') }}
                                @if ($mapping?->disabled_reason)
                                    · <span class="text-red-600 dark:text-red-400" data-test="disabled-reason">{{ __('Switched off: the template was :reason at Meta', ['reason' => str_replace(['template_', '_'], ['', ' '], $mapping->disabled_reason)]) }}</span>
                                @endif
                            </span>
                        </span>
                        <span class="flex items-center gap-2">
                            <flux:badge size="sm" :color="$mapping?->active ? 'green' : 'zinc'">{{ $mapping?->active ? __('WhatsApp on') : __('WhatsApp off') }}</flux:badge>
                            @if ($canManage)
                                <flux:button size="sm" wire:click="edit('{{ $row['event']->key }}')" data-test="edit-message">{{ __('Edit') }}</flux:button>
                            @endif
                        </span>
                    </li>
                @endforeach
            </ul>
        </section>
    @endforeach
</x-page>
