{{--
    One ticket in the tenant portal's repairs list: a stripe in the
    agency's colour, the number (or "Draft"), the status badge, the title
    and the home. The whole card is the link (a big target). $row
    (Lists\TicketRow), $colour.
--}}
@php($ticket = $row->ticket)
<a href="{{ $ticket->status === \Modules\Tickets\Enums\TicketStatus::Draft ? route('portal.tickets.edit', $ticket->id) : route('portal.tickets.show', $ticket->id) }}" wire:navigate
    class="block overflow-hidden rounded-xl border border-zinc-200 bg-white hover:border-zinc-300 dark:border-zinc-700 dark:bg-zinc-900"
    data-test="portal-ticket" data-ticket="{{ $ticket->id }}">
    <span class="block h-1.5" style="background-color: {{ $colour }}" aria-hidden="true"></span>
    <span class="flex flex-col gap-2 p-4">
        <span class="flex flex-wrap items-center justify-between gap-2">
            <span class="font-medium break-all text-zinc-900 dark:text-white">{{ $ticket->reference() }}</span>
            <flux:badge size="sm" :color="$ticket->status->color()">{{ __($ticket->status->label()) }}</flux:badge>
        </span>
        <span class="break-words text-zinc-900 dark:text-white">{{ $ticket->title }}</span>
        <span class="text-sm break-words text-zinc-600 dark:text-zinc-300">
            {{ $row->unitLabel }} · {{ $row->buildingName }} · {{ __($ticket->category->label()) }}
        </span>
    </span>
</a>
