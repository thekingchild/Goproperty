{{--
    The owner portal's repairs (Modules\Tickets\Livewire\Owner\OwnerTicketIndex,
    P04-02): one card per agency that manages buildings of the owner, in its
    current colours, with the sent tickets of those buildings. Mobile first
    (360 px, 44 px targets). Read-only.
--}}
@php
    use Modules\Tickets\Tickets\TicketDates;
@endphp
<x-page :heading="__('Repairs')" :description="__('Problems reported in your buildings, and what each agency is doing about them.')">
    <x-slot:actions>
        <flux:button icon="arrow-left" :href="route('owner.home')" wire:navigate class="min-h-11" data-test="owner-home-link">{{ __('My properties') }}</flux:button>
    </x-slot:actions>

    @if ($agencies === [])
        <x-empty-state icon="wrench-screwdriver" :heading="__('No repairs yet')" :description="__('When an agency invites you as the owner of buildings it manages, problems reported there appear here.')" />
    @else
        @foreach ($agencies as $agency)
            <section class="flex flex-col gap-4 rounded-2xl border border-zinc-200 bg-zinc-50 p-4 dark:border-zinc-700 dark:bg-zinc-900/50" style="border-top: 4px solid {{ $agency->colour }}" data-test="owner-agency-tickets" data-organization="{{ $agency->organizationId }}" data-owner="{{ $agency->ownerProfileId }}">
                @include('tickets::partials.agency', ['name' => $agency->name, 'colour' => $agency->colour, 'initials' => $agency->initials, 'organizationId' => $agency->organizationId, 'readOnly' => false])

                @forelse ($agency->tickets as $row)
                    @php($ticket = $row->ticket)
                    <a href="{{ route('owner.tickets.show', $ticket->id) }}" wire:navigate class="flex min-h-11 flex-col gap-2 rounded-xl border border-zinc-200 bg-white p-4 hover:border-zinc-300 dark:border-zinc-700 dark:bg-zinc-900" data-test="owner-ticket" data-ticket="{{ $ticket->id }}">
                        <span class="flex flex-wrap items-center justify-between gap-2">
                            <span class="font-medium break-all text-zinc-900 dark:text-white">{{ $ticket->reference() }}</span>
                            <span class="flex flex-wrap gap-2">
                                <flux:badge size="sm" :color="$ticket->priority->color()">{{ __($ticket->priority->label()) }}</flux:badge>
                                <flux:badge size="sm" :color="$ticket->status->color()">{{ __($ticket->status->label()) }}</flux:badge>
                            </span>
                        </span>
                        <span class="break-words text-zinc-900 dark:text-white">{{ $ticket->title }}</span>
                        <span class="text-sm break-words text-zinc-600 dark:text-zinc-300">{{ $row->unitLabel }} · {{ $row->buildingName }} · {{ __($ticket->category->label()) }}</span>
                        <span class="text-sm text-zinc-500 dark:text-zinc-400">
                            {{ __('Sent :date', ['date' => TicketDates::day($ticket->submitted_at)]) }}
                            @if ($ticket->closed_at)
                                · {{ __('Closed :date', ['date' => TicketDates::day($ticket->closed_at)]) }}
                            @elseif ($ticket->resolved_at)
                                · {{ __('Resolved :date', ['date' => TicketDates::day($ticket->resolved_at)]) }}
                            @endif
                        </span>
                    </a>
                @empty
                    <flux:text data-test="owner-no-tickets">{{ __('No problems reported in your buildings here.') }}</flux:text>
                @endforelse
            </section>
        @endforeach
    @endif
</x-page>
