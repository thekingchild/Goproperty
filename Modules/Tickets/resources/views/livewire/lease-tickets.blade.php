{{--
    A lease's maintenance tickets on its page (Modules\Tickets\Livewire\LeaseTickets, P04-03):
    the newest tickets of the lease and "Raise a ticket" (the staff form on the lease's
    unit and tenant). Nothing shows to a member who may neither see nor raise tickets.
--}}
<div>
    @if ($canView || $canCreate)
        <section class="rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900" data-test="lease-tickets">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <flux:heading size="lg" level="2">{{ __('Maintenance tickets') }}</flux:heading>
                @if ($canCreate)
                    <flux:button size="sm" icon="wrench-screwdriver" :href="route('workspace.tickets.create', [$organization, 'lease' => $leaseId])" wire:navigate data-test="lease-raise-ticket">{{ __('Raise a ticket') }}</flux:button>
                @endif
            </div>

            @if ($canView)
                @if ($tickets === [])
                    <flux:text class="mt-2">{{ __('No tickets for this lease yet.') }}</flux:text>
                @else
                    <ul class="mt-3 divide-y divide-zinc-100 dark:divide-zinc-800" data-test="lease-ticket-list">
                        @foreach ($tickets as $ticket)
                            <li class="flex flex-col gap-1 py-2 sm:flex-row sm:items-center sm:justify-between" data-ticket="{{ $ticket->id }}">
                                <a href="{{ route('workspace.tickets.show', [$organization, $ticket->id]) }}" wire:navigate class="min-w-0 truncate font-medium text-zinc-900 hover:underline dark:text-white">{{ $ticket->number }} · {{ $ticket->title }}</a>
                                <span class="flex items-center gap-2 text-sm text-zinc-500 dark:text-zinc-400">
                                    {{ \Modules\Tickets\Tickets\TicketDates::day($ticket->submittedAt) }}
                                    <flux:badge size="sm" :color="\Modules\Tickets\Enums\TicketStatus::from($ticket->status)->color()">{{ __($ticket->statusLabel) }}</flux:badge>
                                </span>
                            </li>
                        @endforeach
                    </ul>
                    @if ($more > 0)
                        <flux:text class="mt-2 text-sm">{{ trans_choice(':count older ticket is in the tickets list.|:count older tickets are in the tickets list.', $more, ['count' => $more]) }}</flux:text>
                    @endif
                @endif
            @endif
        </section>
    @endif
</div>
