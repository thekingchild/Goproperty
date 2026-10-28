{{--
    Maintenance tickets (/o/{organization}/tickets; Modules\Tickets\Livewire\TicketIndex).
    Rows stack into cards on phones (UX_DESIGN.md § 6). Buttons show only
    for what the member may do; every page and action checks its policy again.
--}}
<x-page :heading="__('Tickets')" :description="__('Problems reported in the homes and buildings :organization manages.', ['organization' => $organization->name])">
    <x-slot:actions>
        <x-list-export list="tickets" />
        @if ($canCreate)
            <flux:button variant="primary" icon="plus" :href="route('workspace.tickets.create', $organization)" wire:navigate data-test="add-ticket">{{ __('Raise a ticket') }}</flux:button>
        @endif
    </x-slot:actions>

    <div class="flex flex-col gap-3">
        <div class="w-full sm:max-w-sm">
            <flux:input wire:model.live.debounce.400ms="search" icon="magnifying-glass" type="search" :placeholder="__('Search by number, title or tenant')" :aria-label="__('Search tickets')" maxlength="100" data-test="ticket-search" />
        </div>

        <div class="grid gap-3 sm:grid-cols-2">
            <flux:select wire:model.live="status" :aria-label="__('Status')" size="sm" data-test="status-filter">
                <option value="">{{ __('All statuses') }}</option>
                <option value="{{ \Modules\Tickets\Lists\TicketList::OPEN }}">{{ __('Open (waiting for you)') }}</option>
                @foreach ($statuses as $option)
                    <option value="{{ $option->value }}">{{ __($option->label()) }}</option>
                @endforeach
            </flux:select>
            <flux:select wire:model.live="building" :aria-label="__('Building')" size="sm" data-test="building-filter">
                <option value="">{{ __('All buildings') }}</option>
                @foreach ($buildings as $buildingId => $buildingName)
                    <option value="{{ $buildingId }}">{{ $buildingName }}</option>
                @endforeach
            </flux:select>
        </div>
    </div>

    @if ($tickets->isEmpty())
        @if ($filtered)
            <x-empty-state icon="magnifying-glass" :heading="__('No ticket matches')" :description="__('Check the spelling, or clear the filters to see every ticket.')">
                <x-slot:action>
                    <flux:button wire:click="clearFilters" data-test="clear-filters">{{ __('Clear filters') }}</flux:button>
                </x-slot:action>
            </x-empty-state>
        @else
            <x-empty-state icon="wrench-screwdriver" :heading="__('No tickets yet')" :description="__('Tenants report problems from their portal. You can also raise a ticket for them, or for a unit with no tenant.')">
                @if ($canCreate)
                    <x-slot:action>
                        <flux:button variant="primary" icon="plus" :href="route('workspace.tickets.create', $organization)" wire:navigate>{{ __('Raise a ticket') }}</flux:button>
                    </x-slot:action>
                @endif
            </x-empty-state>
        @endif
    @else
        <ul class="divide-y divide-zinc-100 rounded-xl border border-zinc-200 bg-white dark:divide-zinc-800 dark:border-zinc-700 dark:bg-zinc-900" data-test="tickets-list">
            @foreach ($rows as $row)
                @php($ticket = $row->ticket)
                <li class="flex flex-col gap-2 p-4 sm:flex-row sm:items-center sm:justify-between" wire:key="ticket-{{ $ticket->id }}" data-ticket="{{ $ticket->id }}">
                    <div class="flex min-w-0 flex-col">
                        <a href="{{ route('workspace.tickets.show', [$organization, $ticket->id]) }}" wire:navigate class="truncate font-medium text-zinc-900 hover:underline dark:text-white">{{ $ticket->number }} · {{ $ticket->title }}</a>
                        <span class="truncate text-sm text-zinc-500 dark:text-zinc-400">{{ $row->unitLabel }} · {{ $row->buildingName }}@if ($row->tenantName) · {{ $row->tenantName }}@endif</span>
                        <span class="text-sm text-zinc-500 dark:text-zinc-400">
                            {{ __($ticket->category->label()) }} · {{ __('Sent :date', ['date' => \Modules\Tickets\Tickets\TicketDates::day($ticket->submitted_at)]) }} · {{ $row->assigneeName ?? __('Not assigned') }}
                        </span>
                    </div>

                    <div class="flex flex-wrap items-center gap-2">
                        @if ($ticket->priority !== \Modules\Tickets\Enums\TicketPriority::Medium)
                            <flux:badge size="sm" :color="$ticket->priority->color()" data-test="priority-{{ $ticket->id }}">{{ __($ticket->priority->label()) }}</flux:badge>
                        @endif
                        <flux:badge size="sm" :color="$ticket->status->color()" data-test="status-{{ $ticket->id }}">{{ __($ticket->status->label()) }}</flux:badge>
                    </div>
                </li>
            @endforeach
        </ul>

        <flux:pagination :paginator="$tickets" />
    @endif
</x-page>
