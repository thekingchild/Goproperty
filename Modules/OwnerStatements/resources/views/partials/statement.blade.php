{{--
    One owner's month (P08-05), on the agency's month page and the owner
    portal's: the summary with the net due, the agency's mark, then each
    building's rent and utilities collected per unit, every amount counted,
    the bills issued and the expenses charged. Cards, no tables (UX_DESIGN.md
    § 1: 360 px first). Tenants by name only.
    $month: Modules\OwnerStatements\Statements\MonthStatement.
--}}
@php
    $statement = $month->statement;
    $totals = $statement->totals;
    $settlement = $month->settlement;
@endphp
<section class="flex flex-col gap-3 rounded-2xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900" data-test="statement-summary"
    data-month="{{ $statement->month->key() }}" data-status="{{ $month->status() }}"
    data-rent="{{ $totals->rent->kobo }}" data-utilities="{{ $totals->utilities->kobo }}" data-commission="{{ $totals->commission->kobo }}"
    data-expenses="{{ $totals->expenses->kobo }}" data-net-due="{{ $totals->netDue->kobo }}">
    <div class="flex flex-wrap items-center justify-between gap-2">
        <flux:heading size="md" level="2">{{ $statement->month->label() }}</flux:heading>
        <flux:badge size="sm" :color="$month->statusColor()" data-test="statement-status">{{ __($month->statusLabel()) }}</flux:badge>
    </div>

    <dl class="flex flex-col gap-2 text-sm">
        <div class="flex justify-between gap-3"><dt class="text-zinc-600 dark:text-zinc-300">{{ __('Rent collected') }}</dt><dd class="font-medium tabular-nums text-zinc-900 dark:text-white">{{ $totals->rent->format() }}</dd></div>
        <div class="flex justify-between gap-3">
            <dt class="text-zinc-600 dark:text-zinc-300" data-test="statement-commission-rate">
                @if ($statement->commissionSet())
                    {{ __('Agency commission (:rate of rent)', ['rate' => $statement->commissionRate()]) }}
                @else
                    {{ __('Commission not set: none taken') }}
                @endif
            </dt>
            <dd class="font-medium tabular-nums text-zinc-900 dark:text-white">{{ $totals->commission->isZero() ? $totals->commission->format() : '−'.$totals->commission->format() }}</dd>
        </div>
        <div class="flex justify-between gap-3">
            <dt class="text-zinc-600 dark:text-zinc-300" data-test="statement-utilities-rule">{{ $statement->utilitiesToOwner ? __('Utilities collected (passed to the owner)') : __('Utilities collected (not passed to the owner)') }}</dt>
            <dd class="font-medium tabular-nums text-zinc-900 dark:text-white">{{ $totals->utilities->format() }}</dd>
        </div>
        <div class="flex justify-between gap-3"><dt class="text-zinc-600 dark:text-zinc-300">{{ __('Expenses charged to the owner') }}</dt><dd class="font-medium tabular-nums text-zinc-900 dark:text-white">{{ $totals->expenses->isZero() ? $totals->expenses->format() : '−'.$totals->expenses->format() }}</dd></div>
        <div class="flex justify-between gap-3 border-t border-zinc-200 pt-2 dark:border-zinc-700"><dt class="font-semibold text-zinc-900 dark:text-white">{{ __('Net due to the owner') }}</dt><dd class="font-semibold tabular-nums text-zinc-900 dark:text-white" data-test="statement-net-due">{{ $totals->netDue->format() }}</dd></div>
    </dl>

    <flux:text class="text-sm" data-test="statement-payout">
        @if ($month->isSettled())
            {{ __('Settled: paid to the owner on :date, reference :reference.', ['date' => $settlement?->paid_on?->format('d/m/Y'), 'reference' => $settlement?->reference]) }}
        @elseif ($statement->month->hasEnded())
            {{ __('Not settled yet: these figures come from today\'s records and may change until the agency marks the month settled.') }}
        @else
            {{ __('This month is still running: its figures change until it ends and is settled.') }}
        @endif
    </flux:text>
</section>

@foreach ($statement->buildings as $building)
    <section class="flex flex-col gap-3 rounded-2xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900" data-test="statement-building" data-building="{{ $building->propertyId }}"
        data-rent="{{ $building->figures->rent->kobo }}" data-utilities="{{ $building->figures->utilities->kobo }}" data-commission="{{ $building->figures->commission->kobo }}"
        data-expenses="{{ $building->figures->expenses->kobo }}" data-net-due="{{ $building->figures->netDue->kobo }}">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <flux:heading size="md" level="3" class="break-words">{{ $building->name }}</flux:heading>
            @if ($building->archived)
                <flux:badge size="sm" color="zinc">{{ __('No longer managed') }}</flux:badge>
            @endif
        </div>
        <flux:text class="text-sm">
            {{ __('Rent :rent · utilities :utilities · commission :commission · expenses :expenses', ['rent' => $building->figures->rent->format(), 'utilities' => $building->figures->utilities->format(), 'commission' => $building->figures->commission->format(), 'expenses' => $building->figures->expenses->format()]) }}
            <br><span class="font-medium text-zinc-900 dark:text-white">{{ __('Net due: :amount', ['amount' => $building->figures->netDue->format()]) }}</span>
        </flux:text>

        @if ($building->units !== [])
            <div class="flex flex-col gap-1" data-test="statement-units">
                <flux:heading size="sm" level="4">{{ __('Collected per unit') }}</flux:heading>
                @foreach ($building->units as $unit)
                    <div class="flex flex-wrap justify-between gap-2 text-sm" data-test="statement-unit" data-unit="{{ $unit->unit }}" data-rent="{{ $unit->rent->kobo }}" data-utilities="{{ $unit->utilities->kobo }}">
                        <span class="break-words text-zinc-900 dark:text-white">{{ $unit->unit !== '' ? $unit->unit : __('The building') }}</span>
                        <span class="tabular-nums text-zinc-600 dark:text-zinc-300">{{ __('Rent :rent · utilities :utilities', ['rent' => $unit->rent->format(), 'utilities' => $unit->utilities->format()]) }}</span>
                    </div>
                @endforeach
            </div>
        @endif

        @if ($building->collections !== [])
            <div class="flex flex-col gap-2" data-test="statement-collections">
                <flux:heading size="sm" level="4">{{ __('Payments counted') }}</flux:heading>
                @foreach ($building->collections as $line)
                    <div class="flex flex-col gap-0.5 rounded-lg bg-zinc-50 p-2 text-sm dark:bg-zinc-800" data-test="statement-collection" data-kind="{{ $line->kind }}" data-amount="{{ $line->amount->kobo }}">
                        <span class="flex flex-wrap justify-between gap-2">
                            <span class="text-zinc-900 dark:text-white">{{ __($line->kindLabel) }} · {{ $line->at->setTimezone('Africa/Lagos')->format('d/m/Y') }}</span>
                            <span class="font-medium tabular-nums text-zinc-900 dark:text-white">{{ $line->amount->format() }}</span>
                        </span>
                        <span class="break-words text-zinc-600 dark:text-zinc-300">{{ __($line->typeLabel) }} · {{ $line->billNumber }}@if ($line->unit !== '') · {{ $line->unit }}@endif @if ($line->tenantName !== '') · {{ $line->tenantName }}@endif</span>
                    </div>
                @endforeach
            </div>
        @endif

        @if ($building->bills !== [])
            <div class="flex flex-col gap-2" data-test="statement-bills">
                <flux:heading size="sm" level="4">{{ __('Bills issued') }}</flux:heading>
                @foreach ($building->bills as $bill)
                    <div class="flex flex-col gap-0.5 rounded-lg bg-zinc-50 p-2 text-sm dark:bg-zinc-800" data-test="statement-bill" data-number="{{ $bill->number }}" data-status="{{ $bill->status }}">
                        <span class="flex flex-wrap items-center justify-between gap-2">
                            <span class="break-all text-zinc-900 dark:text-white">{{ $bill->number }}</span>
                            <flux:badge size="sm" :color="$bill->statusColor">{{ __($bill->statusLabel) }}</flux:badge>
                        </span>
                        <span class="break-words text-zinc-600 dark:text-zinc-300">{{ __($bill->typeLabel) }}@if ($bill->unit !== '') · {{ $bill->unit }}@endif @if ($bill->tenantName !== '') · {{ $bill->tenantName }}@endif · {{ $bill->amount->format() }}</span>
                    </div>
                @endforeach
            </div>
        @endif

        @if ($building->expenses !== [])
            <div class="flex flex-col gap-2" data-test="statement-expenses">
                <flux:heading size="sm" level="4">{{ __('Expenses charged to the owner') }}</flux:heading>
                @foreach ($building->expenses as $expense)
                    <div class="flex flex-wrap justify-between gap-2 rounded-lg bg-zinc-50 p-2 text-sm dark:bg-zinc-800" data-test="statement-expense" data-amount="{{ $expense->amount->kobo }}">
                        <span class="break-words text-zinc-900 dark:text-white">{{ $expense->description }} · {{ $expense->spentOn->format('d/m/Y') }}@if ($expense->unit !== '') · {{ $expense->unit }}@endif</span>
                        <span class="font-medium tabular-nums text-zinc-900 dark:text-white">−{{ $expense->amount->format() }}</span>
                    </div>
                @endforeach
            </div>
        @endif

        @if ($building->collections === [] && $building->bills === [] && $building->expenses === [])
            <flux:text class="text-sm">{{ __('Nothing this month.') }}</flux:text>
        @endif
    </section>
@endforeach
