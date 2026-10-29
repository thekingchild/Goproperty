{{--
    Leases ending in the next 90 days and where their renewal stands
    (P08-05). The tenant's name, phone and email (always, since P03-16).
    $renewals: list<Modules\OwnerStatements\Statements\RenewalLine>; $leaseLinks: bool (owner portal).
--}}
<div class="flex flex-col gap-2" data-test="upcoming-renewals">
    <flux:heading size="md" level="3">{{ __('Upcoming renewals (next 90 days)') }}</flux:heading>
    @forelse ($renewals as $renewal)
        <div class="flex flex-col gap-1 rounded-xl border border-zinc-200 bg-white p-3 text-sm dark:border-zinc-700 dark:bg-zinc-900" data-test="renewal" data-lease="{{ $renewal->leaseId }}" data-state="{{ $renewal->state }}">
            <span class="flex flex-wrap items-center justify-between gap-2">
                <span class="font-medium break-words text-zinc-900 dark:text-white">{{ $renewal->unit }} · {{ $renewal->building }}</span>
                <flux:badge size="sm" :color="$renewal->state === 'not_renewed' ? 'amber' : 'green'">{{ __($renewal->stateLabel) }}</flux:badge>
            </span>
            <span class="break-words text-zinc-600 dark:text-zinc-300" data-test="renewal-tenant">{{ $renewal->tenantName }}@if ($renewal->tenantPhone) · {{ $renewal->tenantPhone }}@endif @if ($renewal->tenantEmail) · {{ $renewal->tenantEmail }}@endif</span>
            <span class="text-zinc-600 dark:text-zinc-300">
                {{ __('Ends :date · :rent :cycle', ['date' => $renewal->endDate->format('d/m/Y'), 'rent' => $renewal->rent->format(), 'cycle' => strtolower(__($renewal->cycleLabel))]) }}@if ($renewal->renewalStart) · {{ __('renewal from :date', ['date' => $renewal->renewalStart->format('d/m/Y')]) }}@endif
            </span>
            @if ($leaseLinks ?? false)
                <a href="{{ route('owner.leases.show', $renewal->leaseId) }}" wire:navigate class="inline-flex min-h-11 items-center font-medium text-zinc-900 underline dark:text-white">{{ __('See the lease') }}</a>
            @endif
        </div>
    @empty
        <flux:text class="text-sm">{{ __('No lease ends in the next 90 days.') }}</flux:text>
    @endforelse
</div>
