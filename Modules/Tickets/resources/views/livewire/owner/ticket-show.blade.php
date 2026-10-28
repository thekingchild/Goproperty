{{--
    A ticket of one of my buildings (Modules\Tickets\Livewire\Owner\OwnerTicketShow,
    P04-02): the agency, the ticket, the conversation (never internal notes;
    a reply only while no agent is assigned) and the history. Phone first:
    one column, 44 px targets. The page itself is read-only.
--}}
@php
    use Modules\Tickets\Tickets\TicketDates;
@endphp
<x-page :heading="$ticket->reference().' · '.$ticket->title" :description="$row->unitLabel.' · '.$row->buildingName">
    <x-slot:actions>
        <flux:button icon="arrow-left" :href="route('owner.tickets.index')" wire:navigate class="min-h-11" data-test="owner-tickets-back">{{ __('Repairs') }}</flux:button>
    </x-slot:actions>

    <section class="flex flex-col gap-4 rounded-2xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900" style="border-top: 6px solid {{ $agency['colour'] }}" data-test="owner-ticket-page" data-ticket="{{ $ticket->id }}">
        @include('tickets::partials.agency', ['name' => $agency['name'], 'colour' => $agency['colour'], 'initials' => $agency['initials'], 'organizationId' => $agency['id'], 'readOnly' => false])

        <div class="flex flex-wrap items-center gap-2">
            <flux:badge size="sm" :color="$ticket->status->color()" data-test="owner-ticket-status">{{ __($ticket->status->label()) }}</flux:badge>
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
                <dt class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Tenant') }}</dt>
                <dd class="break-words text-zinc-900 dark:text-white" data-test="owner-ticket-tenant">
                    {{ $row->tenantName ?? __('No tenant (a shared area)') }}
                    @if ($contact)
                        <span class="block text-sm text-zinc-600 dark:text-zinc-300" data-test="owner-ticket-contact">{{ $contact['phone'] }}@if ($contact['email']) · {{ $contact['email'] }}@endif</span>
                    @endif
                </dd>
            </div>
            <div>
                <dt class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Handled by') }}</dt>
                <dd class="text-zinc-900 dark:text-white" data-test="owner-ticket-assignee">{{ $row->assigneeName ?? __('Nobody at the agency yet') }}</dd>
            </div>
            <div>
                <dt class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Sent') }}</dt>
                <dd class="text-zinc-900 dark:text-white">{{ TicketDates::show($ticket->submitted_at) }}</dd>
            </div>
            @if ($ticket->resolved_at)
                <div>
                    <dt class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Resolved') }}</dt>
                    <dd class="text-zinc-900 dark:text-white">{{ TicketDates::show($ticket->resolved_at) }}</dd>
                </div>
            @endif
            @if ($ticket->closed_at)
                <div>
                    <dt class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Closed') }}</dt>
                    <dd class="text-zinc-900 dark:text-white">{{ TicketDates::show($ticket->closed_at) }}</dd>
                </div>
            @endif
        </dl>
    </section>

    <livewire:tickets::owner.owner-ticket-conversation :ticket-id="$ticket->id" :key="'owner-ticket-conversation-'.$ticket->id" />

    @if ($history !== [])
        <section class="flex flex-col gap-3 rounded-2xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
            <flux:heading size="md" level="2">{{ __('What happened') }}</flux:heading>
            @include('tickets::partials.history', ['history' => $history])
        </section>
    @endif
</x-page>
