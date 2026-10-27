{{--
    The tenant portal's home (Modules\Tenants\Livewire\Portal\PortalHome,
    P03-11a): one card per agency the user rents from, in that agency's
    colours — what is owed and the next bill to pay, the homes leased there,
    the latest bills. Mobile first (UX_DESIGN.md § 1: 360 px, 44 px targets).
--}}
<x-page :heading="__('My rentals')" :description="__('Your homes, leases and bills, from every agency you rent from.')">
    @if (session('status'))
        <flux:callout variant="success" icon="check-circle" :heading="session('status')" role="status" data-test="portal-status" />
    @endif

    @if ($agencies === [])
        <x-empty-state icon="home-modern" :heading="__('No rentals yet')" :description="__('When an agency adds you as a tenant, your lease and bills appear here.')" />
    @else
        @include('tenants::portal.partials.nav')

        @foreach ($agencies as $agency)
            @php($toPay = $agency->toPay())
            <section class="flex flex-col gap-4 rounded-2xl border border-zinc-200 bg-zinc-50 p-4 dark:border-zinc-700 dark:bg-zinc-900/50" style="border-top: 4px solid {{ $agency->colour }}" data-test="portal-agency-card" data-organization="{{ $agency->organizationId() }}">
                @include('tenants::portal.partials.agency', ['agency' => $agency, 'brand' => $agency->latestBill()?->brand])

                @if ($toPay !== [])
                    <div class="flex flex-col gap-3 rounded-xl p-4 text-white" style="background-color: {{ $agency->colour }}" data-test="portal-amount-due">
                        <span class="text-sm opacity-90">{{ __('To pay now') }}</span>
                        <span class="text-2xl font-bold break-words">{{ $agency->amountDue()->format() }}</span>
                        <span class="text-sm opacity-90">{{ trans_choice(':count bill to pay, the next one due :date|:count bills to pay, the next one due :date', count($toPay), ['date' => $toPay[0]->dueDate->format('d/m/Y')]) }}</span>
                        <flux:button :href="route('portal.bills.show', $toPay[0]->id)" wire:navigate icon="banknotes" class="w-full sm:w-auto" data-test="portal-pay-next">{{ __('Pay this bill') }}</flux:button>
                    </div>
                @elseif ($agency->bills !== [])
                    <flux:text data-test="portal-nothing-due">{{ __('Nothing to pay right now.') }}</flux:text>
                @endif

                <div class="flex flex-col gap-3">
                    <flux:heading size="md" level="3">{{ __('My homes') }}</flux:heading>
                    @forelse ($agency->leases as $home)
                        <a href="{{ route('portal.leases.show', $home->lease->id) }}" wire:navigate class="flex flex-col gap-1 rounded-xl border border-zinc-200 bg-white p-4 hover:border-zinc-300 dark:border-zinc-700 dark:bg-zinc-900" data-test="portal-lease" data-lease="{{ $home->lease->id }}">
                            <span class="flex flex-wrap items-center justify-between gap-2">
                                <span class="font-medium break-words text-zinc-900 dark:text-white">{{ $home->unit }} · {{ $home->building }}</span>
                                <flux:badge size="sm" :color="$home->statusColor()">{{ __($home->lease->statusLabel) }}</flux:badge>
                            </span>
                            @if ($home->address)
                                <span class="text-sm break-words text-zinc-600 dark:text-zinc-300">{{ $home->address }}</span>
                            @endif
                            <span class="text-sm text-zinc-600 dark:text-zinc-300">{{ $home->lease->startDate->format('d/m/Y') }} – {{ $home->lease->endDate->format('d/m/Y') }} · {{ __(':amount :cycle', ['amount' => $home->lease->rent->format(), 'cycle' => strtolower($home->lease->cycle->label())]) }}</span>
                        </a>
                    @empty
                        <flux:text>{{ __('No lease yet.') }}</flux:text>
                    @endforelse
                </div>

                @if ($agency->bills !== [])
                    <div class="flex flex-col gap-3">
                        <flux:heading size="md" level="3">{{ __('Latest bills') }}</flux:heading>
                        @foreach (array_slice($toPay !== [] ? $toPay : $agency->bills, 0, 3) as $bill)
                            @include('tenants::portal.partials.bill-card', ['bill' => $bill])
                        @endforeach
                        <flux:button :href="route('portal.bills.index')" wire:navigate variant="ghost" icon-trailing="arrow-right" class="self-start">{{ __('All my bills') }}</flux:button>
                    </div>
                @endif
            </section>
        @endforeach
    @endif
</x-page>
