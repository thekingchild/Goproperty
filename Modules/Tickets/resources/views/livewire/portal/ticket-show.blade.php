{{--
    One of my repairs (Modules\Tickets\Livewire\Portal\PortalTicketShow,
    P04-01): the agency, the ticket, what happened since, and "Confirm it's
    fixed" / "Reopen" while allowed. Phone first: one column, 44 px targets.
--}}
@php
    use Modules\Tickets\Tickets\TicketDates;
@endphp
<x-page :heading="$ticket->reference().' · '.$ticket->title" :description="$row->unitLabel.' · '.$row->buildingName">
    <x-slot:actions>
        <flux:button icon="arrow-left" :href="route('portal.tickets.index')" wire:navigate class="min-h-11" data-test="portal-ticket-back">{{ __('Repairs') }}</flux:button>
    </x-slot:actions>

    <x-portal.nav />

    @if ($notice)
        <flux:callout variant="success" icon="check-circle" :heading="$notice" role="status" data-test="portal-ticket-notice" />
    @elseif ($flash)
        <flux:callout variant="success" icon="check-circle" :heading="$flash" role="status" data-test="portal-ticket-notice" />
    @endif

    @error('ticket')
        <flux:callout variant="warning" icon="exclamation-triangle" :heading="$message" role="alert" data-test="portal-ticket-warning" />
    @enderror

    <section class="flex flex-col gap-4 rounded-2xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900" style="border-top: 6px solid {{ $agency['colour'] }}" data-test="portal-ticket-page" data-ticket="{{ $ticket->id }}">
        @include('tickets::partials.agency', ['name' => $agency['name'], 'colour' => $agency['colour'], 'initials' => $agency['initials'], 'organizationId' => $agency['id'], 'readOnly' => $agency['readOnly']])

        <div class="flex flex-wrap items-center gap-2">
            <flux:badge size="sm" :color="$ticket->status->color()" data-test="portal-ticket-status">{{ __($ticket->status->label()) }}</flux:badge>
            <flux:badge size="sm" :color="$ticket->priority->color()">{{ __($ticket->priority->label()) }}</flux:badge>
        </div>

        @if ($ticket->description)
            <p class="break-words whitespace-pre-line text-zinc-800 dark:text-zinc-200">{{ $ticket->description }}</p>
        @endif

        <dl class="grid grid-cols-1 gap-3 sm:grid-cols-2">
            <div>
                <dt class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Kind of problem') }}</dt>
                <dd class="text-zinc-900 dark:text-white">{{ __($ticket->category->label()) }}</dd>
            </div>
            <div>
                <dt class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Sent') }}</dt>
                <dd class="text-zinc-900 dark:text-white">{{ $ticket->submitted_at ? TicketDates::show($ticket->submitted_at) : __('Not sent yet') }}</dd>
            </div>
        </dl>

        @if ($canEdit)
            <flux:button variant="primary" :href="route('portal.tickets.edit', $ticket->id)" wire:navigate class="min-h-11" data-test="edit-draft">{{ __('Finish and send') }}</flux:button>
        @endif
    </section>

    {{-- Conversation slot: the ticket's thread with the agency (ticket_messages, never the internal notes) is P04-02's and goes here, between the ticket and its actions. --}}

    @if ($canConfirm || $canReopen)
        <section class="flex flex-col gap-4 rounded-2xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900" data-test="portal-ticket-actions">
            @if ($canConfirm)
                <div class="flex flex-col gap-3">
                    <flux:heading size="md" level="2">{{ __('Is it fixed?') }}</flux:heading>
                    <flux:text>{{ __(':agency marked this problem resolved. Confirm it, or reopen it if it is not fixed.', ['agency' => $agency['name']]) }}</flux:text>
                    <flux:button variant="primary" wire:click="confirm" class="min-h-11" data-test="confirm-fixed">{{ __('Confirm it\'s fixed') }}</flux:button>
                </div>
            @endif

            @if ($canReopen)
                <form wire:submit="reopen" class="flex flex-col gap-3">
                    <flux:textarea wire:model="reason" :label="__('Not fixed? Say what is still wrong')" rows="3" maxlength="1000" data-test="reopen-reason" />
                    <flux:text class="text-sm" data-test="reopen-until">{{ __('You can reopen it until :date.', ['date' => TicketDates::show($reopenableUntil)]) }}</flux:text>
                    <flux:button type="submit" class="min-h-11" data-test="reopen-ticket">{{ __('Reopen') }}</flux:button>
                </form>
            @endif
        </section>
    @endif

    @if ($history !== [])
        <section class="flex flex-col gap-3 rounded-2xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
            <flux:heading size="md" level="2">{{ __('What happened') }}</flux:heading>
            @include('tickets::partials.history', ['history' => $history])
        </section>
    @endif
</x-page>
