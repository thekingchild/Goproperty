{{--
    The conversation with the agency on one of my repairs
    (Modules\Tickets\Livewire\Portal\PortalTicketConversation, P04-02):
    the thread (never internal notes) and the reply form. Phone first: one
    column, 44 px targets, no autofocus.
--}}
<div>
    @unless ($draft)
        <section class="flex flex-col gap-4 rounded-2xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900" data-test="portal-ticket-conversation">
            <flux:heading size="md" level="2">{{ __('Messages') }}</flux:heading>

            @if ($notice)
                <flux:callout variant="success" icon="check-circle" :heading="$notice" role="status" data-test="message-notice" />
            @endif

            @error('ticket')
                <flux:callout variant="warning" icon="exclamation-triangle" :heading="$message" role="alert" data-test="message-warning" />
            @enderror

            @if ($messages === [])
                <flux:text data-test="thread-empty">{{ __('No messages yet. Write to the agency here, and add photos of the problem if they help.') }}</flux:text>
            @else
                @include('tickets::partials.thread', ['messages' => $messages, 'fileUrl' => $fileUrl])
            @endif

            @if ($closed)
                <flux:text class="text-sm" data-test="thread-closed">{{ __('This ticket is closed, so its conversation has ended.') }}</flux:text>
            @elseif ($canReply)
                <form wire:submit="send" class="flex flex-col gap-3" data-test="message-form">
                    <flux:textarea wire:model="body" :label="__('Your message')" rows="3" maxlength="{{ \Modules\Tickets\Messages\TicketMessages::BODY_MAX }}" data-test="message-body" />
                    @if ($canAttach)
                        @include('tickets::partials.message-files', ['id' => 'portal-message-files'])
                    @endif
                    <flux:button type="submit" variant="primary" icon="paper-airplane" class="min-h-11 w-full sm:w-auto" wire:loading.attr="disabled" wire:target="send,files" data-test="send-message">{{ __('Send') }}</flux:button>
                </form>
            @endif
        </section>
    @endunless
</div>
