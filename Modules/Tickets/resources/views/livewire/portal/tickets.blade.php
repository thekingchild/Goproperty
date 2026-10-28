{{--
    Repairs (Modules\Tickets\Livewire\Portal\PortalTickets): "Report a
    problem", the inbox's two tabs (P04-03: Open, Finished) and per agency
    that tab's tickets: open — "Fixed? Please check" (resolved), being dealt
    with, drafts — or finished (closed). Phone first: cards, 44 px targets.
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
        {{-- The inbox (P04-03): open or finished, with the counts of every agency together. --}}
        <div class="grid grid-cols-2 gap-2" role="group" aria-label="{{ __('Show repairs') }}" data-test="portal-ticket-tabs">
            @foreach ([['value' => 'open', 'label' => __('Open'), 'count' => $openCount], ['value' => 'finished', 'label' => __('Finished'), 'count' => $finishedCount]] as $tab)
                <button type="button" wire:click="$set('show', '{{ $tab['value'] }}')"
                    @class([
                        'inline-flex min-h-11 items-center justify-center gap-2 rounded-lg border px-3 text-sm font-medium',
                        'border-zinc-900 bg-zinc-900 text-white dark:border-white dark:bg-white dark:text-zinc-900' => $show === $tab['value'],
                        'border-zinc-200 bg-white text-zinc-700 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-300' => $show !== $tab['value'],
                    ])
                    aria-pressed="{{ $show === $tab['value'] ? 'true' : 'false' }}"
                    data-test="portal-tab-{{ $tab['value'] }}" data-count="{{ $tab['count'] }}">
                    {{ $tab['label'] }}
                    <span class="tabular-nums">{{ $tab['count'] }}</span>
                </button>
            @endforeach
        </div>

        @foreach ($agencies as $agency)
            <section class="flex flex-col gap-4" data-test="portal-agency-tickets" data-organization="{{ $agency->organizationId }}">
                @include('tickets::partials.agency', ['name' => $agency->name, 'colour' => $agency->colour, 'initials' => $agency->initials, 'organizationId' => $agency->organizationId, 'readOnly' => $agency->readOnly])

                @if ($finished)
                    @if ($agency->closed() === [])
                        <flux:text>{{ __('No finished repairs with :agency yet.', ['agency' => $agency->name]) }}</flux:text>
                    @else
                        <div class="flex flex-col gap-3" data-test="portal-ticket-closed">
                            @foreach ($agency->closed() as $row)
                                @include('tickets::partials.portal-ticket-card', ['row' => $row, 'colour' => $agency->colour])
                            @endforeach
                        </div>
                    @endif
                @else
                    @if ($agency->tickets === [])
                        <flux:text>{{ __('No problems reported to :agency yet.', ['agency' => $agency->name]) }}</flux:text>
                    @elseif ($agency->openCount() === 0)
                        <flux:text>{{ __('Nothing open with :agency.', ['agency' => $agency->name]) }}</flux:text>
                    @endif

                    @if ($agency->waitingForYou() !== [])
                        <div class="flex flex-col gap-3" data-test="portal-ticket-waiting">
                            <flux:heading size="md" level="3">{{ __('Fixed? Please check') }}</flux:heading>
                            @foreach ($agency->waitingForYou() as $row)
                                @include('tickets::partials.portal-ticket-card', ['row' => $row, 'colour' => $agency->colour])
                            @endforeach
                        </div>
                    @endif

                    @if ($agency->beingDealtWith() !== [])
                        <div class="flex flex-col gap-3" data-test="portal-ticket-current">
                            <flux:heading size="md" level="3">{{ __('Being dealt with') }}</flux:heading>
                            @foreach ($agency->beingDealtWith() as $row)
                                @include('tickets::partials.portal-ticket-card', ['row' => $row, 'colour' => $agency->colour])
                            @endforeach
                        </div>
                    @endif

                    @if ($agency->drafts() !== [])
                        <div class="flex flex-col gap-3" data-test="portal-ticket-drafts">
                            <flux:heading size="md" level="3">{{ __('Drafts: not sent yet') }}</flux:heading>
                            @foreach ($agency->drafts() as $row)
                                @include('tickets::partials.portal-ticket-card', ['row' => $row, 'colour' => $agency->colour])
                            @endforeach
                        </div>
                    @endif
                @endif
            </section>
        @endforeach
    @endif
</x-page>
