{{--
    A ticket's conversation on its workspace page (Modules\Tickets\Livewire\TicketConversation,
    P04-02): the thread (internal notes marked) and the reply form. The
    server checks who may write, the ticket's state and every file again.
--}}
<section class="flex flex-col gap-4 rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900" data-test="ticket-conversation">
    <flux:heading size="lg" level="2">{{ __('Conversation') }}</flux:heading>

    @if ($notice)
        <flux:callout variant="success" icon="check-circle" :heading="$notice" role="status" data-test="message-notice" />
    @endif

    @error('ticket')
        <flux:callout variant="warning" icon="exclamation-triangle" :heading="$message" role="alert" data-test="message-warning" />
    @enderror

    @if ($messages === [])
        <flux:text data-test="thread-empty">{{ __('No messages yet. Replies reach the tenant (and the owner while nobody is assigned); internal notes stay with staff.') }}</flux:text>
    @else
        @include('tickets::partials.thread', ['messages' => $messages, 'fileUrl' => $fileUrl])
    @endif

    @if ($closed)
        <flux:text data-test="thread-closed">{{ __('This ticket is closed, so its conversation has ended. Reopen it to write again.') }}</flux:text>
    @elseif ($canReply || $canNote)
        <form wire:submit="send" class="flex flex-col gap-3 border-t border-zinc-200 pt-4 dark:border-zinc-700" data-test="message-form">
            <flux:textarea wire:model="body" :label="__('Message')" rows="3" maxlength="{{ \Modules\Tickets\Messages\TicketMessages::BODY_MAX }}" data-test="message-body" />
            @if ($canNote)
                <flux:checkbox wire:model="internal" :label="__('Internal note: only staff see it, never the tenant or the owner')" data-test="message-internal" />
            @endif
            @if ($canAttach)
                @include('tickets::partials.message-files', ['id' => 'ticket-message-files'])
            @endif
            <div>
                <flux:button type="submit" variant="primary" icon="paper-airplane" wire:loading.attr="disabled" wire:target="send,files" data-test="send-message">{{ __('Send') }}</flux:button>
            </div>
        </form>
    @endif
</section>
