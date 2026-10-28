{{--
    The owner portal's statements (Modules\OwnerStatements\Livewire\Portal\PortalStatements,
    P08-05): one card per agency that manages buildings of the owner, in its
    current colours and logo, with the upcoming renewals and the last 12
    months. Mobile first (360 px, 44 px targets). Read-only.
--}}
<x-page :heading="__('Statements')" :description="__('What each agency collected for you every month, its commission, the expenses it charged and what it owes you.')">
    <x-slot:actions>
        <flux:button icon="arrow-left" :href="route('owner.home')" wire:navigate class="min-h-11">{{ __('My properties') }}</flux:button>
    </x-slot:actions>

    @if ($agencies === [])
        <x-empty-state icon="document-text" :heading="__('No statements yet')" :description="__('When an agency invites you as the owner of buildings it manages, their statements appear here.')" />
    @else
        @foreach ($agencies as $section)
            <section class="flex flex-col gap-4 rounded-2xl border border-zinc-200 bg-zinc-50 p-4 dark:border-zinc-700 dark:bg-zinc-900/50" style="border-top: 4px solid {{ $section['agency']->colour }}" data-test="statement-agency" data-organization="{{ $section['agency']->organizationId }}" data-owner="{{ $section['owner']->ownerProfileId }}">
                @include('ownerstatements::partials.agency', ['agency' => $section['agency'], 'subtitle' => $section['terms']->commissionBps === null ? __('Commission not set yet') : __('Commission: :rate of rent collected', ['rate' => \Modules\OwnerStatements\Statements\OwnerStatement::rate($section['terms']->commissionBps)])])

                @include('ownerstatements::partials.renewals', ['renewals' => $section['renewals'], 'leaseLinks' => true])

                <div class="flex flex-col gap-2">
                    <flux:heading size="md" level="3">{{ __('Months') }}</flux:heading>
                    @foreach ($section['months'] as $month)
                        <a href="{{ route('owner.statements.show', [$section['owner']->ownerProfileId, $month->month()->key()]) }}" wire:navigate class="flex min-h-11 flex-wrap items-center justify-between gap-2 rounded-xl border border-zinc-200 bg-white p-3 hover:border-zinc-300 dark:border-zinc-700 dark:bg-zinc-900" data-test="statement-month" data-month="{{ $month->month()->key() }}" data-status="{{ $month->status() }}" data-net-due="{{ $month->statement->totals->netDue->kobo }}">
                            <span class="font-medium text-zinc-900 dark:text-white">{{ $month->month()->label() }}</span>
                            <span class="flex items-center gap-2">
                                <span class="tabular-nums text-zinc-900 dark:text-white">{{ $month->statement->totals->netDue->format() }}</span>
                                <flux:badge size="sm" :color="$month->statusColor()">{{ __($month->statusLabel()) }}</flux:badge>
                            </span>
                        </a>
                    @endforeach
                </div>
            </section>
        @endforeach
    @endif
</x-page>
