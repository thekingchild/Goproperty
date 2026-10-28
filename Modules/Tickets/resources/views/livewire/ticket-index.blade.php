{{--
    The staff tickets inbox (/o/{organization}/tickets; Modules\Tickets\Livewire\TicketIndex):
    tabs by status and by assignee with their counts (P04-03), search and building.
    Rows stack into cards on phones (UX_DESIGN.md § 6); the status tabs scroll sideways there. Buttons show only
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
        {{-- The inbox by status (P04-03): each tab counts what it would list with the other filters. --}}
        <nav class="-mx-1 overflow-x-auto" aria-label="{{ __('Tickets by status') }}" data-test="status-tabs">
            <ul class="flex w-max gap-1 px-1 pb-1">
                @foreach ([['value' => \Modules\Tickets\Lists\TicketList::OPEN, 'label' => __('Open'), 'count' => $statusCounts[\Modules\Tickets\Lists\TicketList::OPEN]], ...array_map(fn ($option) => ['value' => $option->value, 'label' => __($option->label()), 'count' => $statusCounts[$option->value]], $statuses), ['value' => '', 'label' => __('All'), 'count' => $statusCounts['all']]] as $tab)
                    <li>
                        <button type="button" wire:click="$set('status', '{{ $tab['value'] }}')"
                            @class([
                                'inline-flex min-h-10 items-center gap-2 rounded-lg px-3 text-sm font-medium whitespace-nowrap',
                                'bg-zinc-900 text-white dark:bg-white dark:text-zinc-900' => $status === $tab['value'],
                                'text-zinc-600 hover:bg-zinc-100 dark:text-zinc-300 dark:hover:bg-zinc-800' => $status !== $tab['value'],
                            ])
                            @if ($status === $tab['value']) aria-current="true" @endif
                            data-test="status-tab-{{ $tab['value'] === '' ? 'all' : $tab['value'] }}" data-count="{{ $tab['count'] }}">
                            {{ $tab['label'] }}
                            <span class="rounded-full bg-zinc-200/70 px-2 text-xs tabular-nums text-zinc-700 dark:bg-zinc-700 dark:text-zinc-200">{{ $tab['count'] }}</span>
                        </button>
                    </li>
                @endforeach
            </ul>
        </nav>

        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <div class="flex flex-wrap gap-1" role="group" aria-label="{{ __('Assigned to') }}" data-test="assignee-tabs">
                @foreach ([['value' => '', 'label' => __('Anyone'), 'count' => $assigneeCounts['all']], ['value' => \Modules\Tickets\Lists\TicketList::MINE, 'label' => __('Assigned to me'), 'count' => $assigneeCounts[\Modules\Tickets\Lists\TicketList::MINE]], ['value' => \Modules\Tickets\Lists\TicketList::UNASSIGNED, 'label' => __('Unassigned'), 'count' => $assigneeCounts[\Modules\Tickets\Lists\TicketList::UNASSIGNED]]] as $tab)
                    <button type="button" wire:click="$set('assignee', '{{ $tab['value'] }}')"
                        @class([
                            'inline-flex min-h-10 items-center gap-2 rounded-lg border px-3 text-sm whitespace-nowrap',
                            'border-zinc-900 bg-zinc-900 text-white dark:border-white dark:bg-white dark:text-zinc-900' => $assignee === $tab['value'],
                            'border-zinc-200 text-zinc-700 hover:bg-zinc-50 dark:border-zinc-700 dark:text-zinc-300 dark:hover:bg-zinc-800' => $assignee !== $tab['value'],
                        ])
                        aria-pressed="{{ $assignee === $tab['value'] ? 'true' : 'false' }}"
                        data-test="assignee-tab-{{ $tab['value'] === '' ? 'all' : $tab['value'] }}" data-count="{{ $tab['count'] }}">
                        {{ $tab['label'] }}
                        <span class="tabular-nums">{{ $tab['count'] }}</span>
                    </button>
                @endforeach
            </div>

            <div class="grid gap-3 sm:w-auto sm:grid-cols-2">
                <flux:input wire:model.live.debounce.400ms="search" icon="magnifying-glass" type="search" :placeholder="__('Search by number, title or tenant')" :aria-label="__('Search tickets')" maxlength="100" size="sm" data-test="ticket-search" />
                <flux:select wire:model.live="building" :aria-label="__('Building')" size="sm" data-test="building-filter">
                    <option value="">{{ __('All buildings') }}</option>
                    @foreach ($buildings as $buildingId => $buildingName)
                        <option value="{{ $buildingId }}">{{ $buildingName }}</option>
                    @endforeach
                </flux:select>
            </div>
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
