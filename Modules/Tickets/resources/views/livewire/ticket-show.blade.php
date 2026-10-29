{{--
    A ticket (Modules\Tickets\Livewire\TicketShow). Actions show only when
    the ticket's state and the member's permissions allow them; the server
    checks both again.
--}}
@php
    use Modules\Tickets\Tickets\TicketDates;
@endphp
<x-page :heading="$ticket->number.' · '.$ticket->title" :description="__(':unit, :building', ['unit' => $row->unitLabel, 'building' => $row->buildingName])">
    <x-slot:actions>
        <flux:button icon="arrow-left" :href="route('workspace.tickets.index', $organization)" wire:navigate>{{ __('All tickets') }}</flux:button>
        @if ($canReview)
            <flux:button wire:click="startReview" data-test="start-review">{{ __('Start review') }}</flux:button>
        @endif
        @if ($canStart)
            <flux:button variant="primary" wire:click="startWork" data-test="start-work">{{ __('Start work') }}</flux:button>
        @endif
    </x-slot:actions>

    @if ($notice)
        <flux:callout variant="success" icon="check-circle" :heading="$notice" role="status" data-test="ticket-notice" />
    @elseif ($flash)
        <flux:callout variant="success" icon="check-circle" :heading="$flash" role="status" data-test="ticket-notice" />
    @endif

    @error('ticket')
        <flux:callout variant="warning" icon="exclamation-triangle" :heading="$message" role="alert" data-test="ticket-warning" />
    @enderror

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="flex flex-col gap-6 lg:col-span-2">
            <section class="rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900" data-test="ticket-details">
                <div class="flex flex-wrap items-center gap-2">
                    <flux:heading size="lg" level="2">{{ __('The problem') }}</flux:heading>
                    <flux:badge size="sm" :color="$ticket->status->color()" data-test="ticket-status">{{ __($ticket->status->label()) }}</flux:badge>
                    <flux:badge size="sm" :color="$ticket->priority->color()" data-test="ticket-priority">{{ __($ticket->priority->label()) }}</flux:badge>
                    @if ($ticket->reopen_count > 0)
                        <flux:badge size="sm" color="red" data-test="ticket-reopened">{{ trans_choice('Reopened once|Reopened :count times', $ticket->reopen_count, ['count' => $ticket->reopen_count]) }}</flux:badge>
                    @endif
                </div>

                <p class="mt-3 break-words whitespace-pre-line text-zinc-800 dark:text-zinc-200" data-test="ticket-description">{{ $ticket->description }}</p>

                @if ($ticket->movedFromLine() || $ticket->movedToLine())
                    <flux:text class="mt-3" data-test="ticket-moved">{{ $ticket->movedFromLine() ?? $ticket->movedToLine() }}</flux:text>
                @endif

                <dl class="mt-4 grid gap-4 sm:grid-cols-2">
                    <div>
                        <dt class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Kind of problem') }}</dt>
                        <dd class="text-zinc-900 dark:text-white">{{ __($ticket->category->label()) }}</dd>
                    </div>
                    <div>
                        <dt class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Tenant') }}</dt>
                        <dd class="text-zinc-900 dark:text-white" data-test="ticket-tenant">
                            @if ($ticket->tenant_profile_id !== null)
                                <a href="{{ route('workspace.tenants.show', [$organization, $ticket->tenant_profile_id]) }}" wire:navigate class="hover:underline">{{ $row->tenantName }}</a>
                            @else
                                {{ __('No tenant') }}
                            @endif
                        </dd>
                    </div>
                    <div>
                        <dt class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Raised by') }}</dt>
                        <dd class="text-zinc-900 dark:text-white" data-test="ticket-source">{{ $ticket->source === \Modules\Tickets\Enums\TicketSource::Portal ? __('The tenant, in the portal') : ($row->raisedByName ?? __('Staff')) }}</dd>
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
                    @if ($reopenableUntil)
                        <div>
                            <dt class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('The tenant can reopen it until') }}</dt>
                            <dd class="text-zinc-900 dark:text-white" data-test="reopenable-until">{{ TicketDates::show($reopenableUntil) }}</dd>
                        </div>
                    @endif
                </dl>
            </section>

            {{-- Conversation slot: the ticket's thread (ticket_messages: replies and staff-only internal notes), P04-02. --}}
            <livewire:tickets::ticket-conversation :ticket-id="$ticket->id" :key="'ticket-conversation-'.$ticket->id" />

            @if ($canResolve || $canClose || $canReopen)
                <section class="rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900" data-test="ticket-actions">
                    <flux:heading size="lg" level="2">{{ __('Next step') }}</flux:heading>

                    @if ($canResolve || $canClose)
                        <form wire:submit="{{ $canResolve ? 'resolve' : 'close' }}" class="mt-4 flex flex-col gap-3">
                            <flux:textarea wire:model="note" :label="$canResolve ? __('What was done (optional; the tenant sees it)') : __('Note (optional; the tenant sees it)')" rows="3" maxlength="1000" data-test="ticket-note" />
                            <div class="flex flex-wrap gap-3">
                                @if ($canResolve)
                                    <flux:button type="submit" variant="primary" data-test="resolve">{{ __('Mark resolved') }}</flux:button>
                                @else
                                    <flux:button type="submit" variant="primary" data-test="close" wire:confirm="{{ __('Close this ticket? Do this when the tenant has confirmed it is fixed, or there is no tenant to ask.') }}">{{ __('Close the ticket') }}</flux:button>
                                @endif
                            </div>
                        </form>
                    @endif

                    @if ($canReopen)
                        <form wire:submit="reopen" class="mt-4 flex flex-col gap-3">
                            <flux:textarea wire:model="reason" :label="__('Why reopen it (the tenant sees it)')" rows="2" maxlength="1000" data-test="ticket-reason" />
                            <div>
                                <flux:button type="submit" data-test="reopen">{{ __('Reopen') }}</flux:button>
                            </div>
                        </form>
                    @endif
                </section>
            @endif
        </div>

        <div class="flex flex-col gap-6">
            <section class="rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900" data-test="ticket-assignment">
                <flux:heading size="lg" level="2">{{ __('Assigned to') }}</flux:heading>
                @if ($canUpdate)
                    <form wire:submit="assign" class="mt-3 flex flex-col gap-3">
                        <flux:select wire:model="assigneeId" :aria-label="__('Assigned to')" data-test="assignee">
                            <option value="">{{ __('Nobody') }}</option>
                            @foreach ($assignees as $optionId => $optionName)
                                <option value="{{ $optionId }}">{{ $optionName }}</option>
                            @endforeach
                        </flux:select>
                        @error('assigneeId')
                            <flux:text class="text-sm text-red-600 dark:text-red-400">{{ $message }}</flux:text>
                        @enderror
                        <div>
                            <flux:button type="submit" size="sm" data-test="save-assignee">{{ __('Save') }}</flux:button>
                        </div>
                    </form>
                @else
                    <flux:text class="mt-2" data-test="assignee-name">{{ $row->assigneeName ?? __('Nobody') }}</flux:text>
                @endif
            </section>

            {{-- P04-06: the agency chooses which tickets the building's owner sees in /owner. The tenant is never told. --}}
            <section class="rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900" data-test="ticket-owner-sharing">
                <flux:heading size="lg" level="2">{{ __('Building owner') }}</flux:heading>
                @if ($ticket->sharedWithOwner())
                    <flux:text class="mt-2" data-test="ticket-shared-with-owner">{{ __('Shared with the owner by :name on :date.', ['name' => $buildingOwner['sharedBy'], 'date' => TicketDates::day($ticket->shared_with_owner_at)]) }}</flux:text>
                    @if ($buildingOwner['exists'] && ! $buildingOwner['hasPortal'])
                        <flux:text class="mt-2 text-sm" data-test="ticket-owner-no-portal">{{ __(':owner has no owner portal account yet, so nobody is told. They will see it once they join.', ['owner' => $buildingOwner['name']]) }}</flux:text>
                    @endif
                    @if ($canStopSharing)
                        <div class="mt-3">
                            <flux:button size="sm" wire:click="stopSharingWithOwner" wire:confirm="{{ __('Stop sharing this ticket? The owner will no longer see it or hear about it. They are not told.') }}" data-test="stop-sharing-with-owner">{{ __('Stop sharing') }}</flux:button>
                        </div>
                    @endif
                @elseif (! $buildingOwner['exists'])
                    <flux:text class="mt-2" data-test="ticket-no-owner">{{ __('This building has no owner on record, so there is nobody to share the ticket with.') }}</flux:text>
                @else
                    <flux:text class="mt-2" data-test="ticket-not-shared">{{ __(':owner does not see this ticket. Share it if they should follow it: they are told now, then when its status changes or someone replies (never internal notes).', ['owner' => $buildingOwner['name']]) }}</flux:text>
                    @if ($canShare)
                        <div class="mt-3">
                            <flux:button size="sm" wire:click="shareWithOwner" data-test="share-with-owner">{{ __('Share with the owner') }}</flux:button>
                        </div>
                    @endif
                @endif
            </section>

            @if ($canUpdate)
                <section class="rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900" data-test="ticket-details-form">
                    <flux:heading size="lg" level="2">{{ __('Category and priority') }}</flux:heading>
                    <form wire:submit="saveDetails" class="mt-3 flex flex-col gap-3">
                        <flux:select wire:model="category" :label="__('Kind of problem')" data-test="category">
                            @foreach ($categories as $option)
                                <option value="{{ $option->value }}">{{ __($option->label()) }}</option>
                            @endforeach
                        </flux:select>
                        <flux:select wire:model="priority" :label="__('Priority')" data-test="priority">
                            @foreach ($priorities as $option)
                                <option value="{{ $option->value }}">{{ __($option->label()) }}</option>
                            @endforeach
                        </flux:select>
                        <div>
                            <flux:button type="submit" size="sm" data-test="save-details">{{ __('Save') }}</flux:button>
                        </div>
                    </form>
                </section>
            @endif

            <section class="rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900">
                <flux:heading size="lg" level="2">{{ __('History') }}</flux:heading>
                <div class="mt-3">
                    @include('tickets::partials.history', ['history' => $history])
                </div>
            </section>
        </div>
    </div>
</x-page>
