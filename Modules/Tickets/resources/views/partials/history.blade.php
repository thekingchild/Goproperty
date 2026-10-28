{{--
    A ticket's status history, oldest first (Tickets\TicketHistory): each
    move with who made it, when (Lagos) and its note. $history: the rows.
--}}
<ol class="flex flex-col gap-3" data-test="ticket-history">
    @foreach ($history as $change)
        <li class="flex flex-col gap-1 border-s-2 border-zinc-200 ps-3 dark:border-zinc-700" data-test="history-{{ $change['to']->value }}">
            <span class="flex flex-wrap items-center gap-2">
                <flux:badge size="sm" :color="$change['to']->color()">{{ __($change['to']->label()) }}</flux:badge>
                <span class="text-sm text-zinc-600 dark:text-zinc-300">{{ $change['by'] }} · {{ \Modules\Tickets\Tickets\TicketDates::show($change['at']) }}</span>
            </span>
            @if ($change['note'])
                <span class="text-sm break-words whitespace-pre-line text-zinc-800 dark:text-zinc-200">{{ $change['note'] }}</span>
            @endif
        </li>
    @endforeach
</ol>
