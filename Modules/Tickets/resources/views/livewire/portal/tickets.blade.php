{{--
    Repairs (Modules\Tickets\Livewire\Portal\PortalTickets, P04-01): "Report
    a problem", and per agency the user's drafts, the problems being dealt
    with and the closed ones. Phone first: cards, 44 px targets.
--}}
<x-page :heading="__('Repairs')" :description="__('Report a problem in your home and follow what the agency does about it.')">
    <x-slot:actions>
        @if ($canRaise)
            <flux:button variant="primary" icon="plus" :href="route('portal.tickets.create')" wire:navigate class="min-h-11" data-test="report-problem">{{ __('Report a problem') }}</flux:button>
        @endif
    </x-slot:actions>

    <x-portal.nav />

    @if (session('portal-ticket-status'))
        <flux:callout variant="success" icon="check-circle" :heading="session('portal-ticket-status')" role="status" data-test="portal-ticket-notice" />
    @endif

    @if ($agencies === [])
        <x-empty-state icon="wrench-screwdriver" :heading="__('No repairs yet')" :description="__('When an agency adds you as a tenant, you can report problems in your home here.')" />
    @else
        @foreach ($agencies as $agency)
            <section class="flex flex-col gap-4" data-test="portal-agency-tickets" data-organization="{{ $agency->organizationId }}">
                @include('tickets::partials.agency', ['name' => $agency->name, 'colour' => $agency->colour, 'initials' => $agency->initials, 'organizationId' => $agency->organizationId, 'readOnly' => $agency->readOnly])

                @if ($agency->tickets === [])
                    <flux:text>{{ __('No problems reported to :agency yet.', ['agency' => $agency->name]) }}</flux:text>
                @endif

                @if ($agency->drafts() !== [])
                    <div class="flex flex-col gap-3" data-test="portal-ticket-drafts">
                        <flux:heading size="md" level="3">{{ __('Drafts: not sent yet') }}</flux:heading>
                        @foreach ($agency->drafts() as $row)
                            @include('tickets::partials.portal-ticket-card', ['row' => $row, 'colour' => $agency->colour])
                        @endforeach
                    </div>
                @endif

                @if ($agency->current() !== [])
                    <div class="flex flex-col gap-3" data-test="portal-ticket-current">
                        <flux:heading size="md" level="3">{{ __('Being dealt with') }}</flux:heading>
                        @foreach ($agency->current() as $row)
                            @include('tickets::partials.portal-ticket-card', ['row' => $row, 'colour' => $agency->colour])
                        @endforeach
                    </div>
                @endif

                @if ($agency->closed() !== [])
                    <div class="flex flex-col gap-3" data-test="portal-ticket-closed">
                        <flux:heading size="md" level="3">{{ __('Closed') }}</flux:heading>
                        @foreach ($agency->closed() as $row)
                            @include('tickets::partials.portal-ticket-card', ['row' => $row, 'colour' => $agency->colour])
                        @endforeach
                    </div>
                @endif
            </section>
        @endforeach
    @endif
</x-page>
