{{--
    Reports (/o/{organization}/reports; Modules\Reports\Livewire\ReportsDashboard).
    The customer dashboards for the range and building picked above them;
    each report has its own Export (Excel, CSV), the page a PDF. Wide tables
    scroll sideways inside their card on phones, never the page.
    $report: Modules\Reports\Reports\PortfolioReport.
--}}
@php
    $rate = fn ($rate) => \Modules\Reports\Reports\Rate::labelOf($rate);
    $card = 'rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900';
    $th = 'px-3 py-2 text-start text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400';
    $thr = 'px-3 py-2 text-end text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400';
    $td = 'px-3 py-2 text-zinc-900 dark:text-white';
    $tdr = 'px-3 py-2 text-end tabular-nums text-zinc-900 dark:text-white whitespace-nowrap';
    $groups = \Modules\Reports\Reports\FlowTotals::GROUPS;
@endphp

<x-page :heading="__('Reports')" :description="__(':organization from :range:building.', ['organization' => $organization->name, 'range' => $filters->label(), 'building' => $report->buildingName !== null ? ' · '.$report->buildingName : ''])">
    <x-slot:actions>
        <flux:button icon="document-arrow-down" :href="$pdfUrl" data-test="report-pdf">{{ __('Download PDF') }}</flux:button>
    </x-slot:actions>

    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4" data-test="report-filters">
        <flux:select wire:model.live="period" :label="__('Period')" size="sm" data-test="period-filter">
            @foreach ($presets as $preset)
                <option value="{{ $preset->value }}">{{ __($preset->label()) }}</option>
            @endforeach
        </flux:select>
        <flux:input wire:model.live.blur="from" type="date" size="sm" :label="__('From')" data-test="from-filter" />
        <flux:input wire:model.live.blur="to" type="date" size="sm" :label="__('To')" data-test="to-filter" />
        <flux:select wire:model.live="building" :label="__('Building')" size="sm" data-test="building-filter">
            <option value="">{{ __('All buildings') }}</option>
            @foreach ($buildings as $buildingId => $buildingName)
                <option value="{{ $buildingId }}">{{ $buildingName }}</option>
            @endforeach
        </flux:select>
    </div>

    {{-- The headline figures. --}}
    <div class="grid grid-cols-2 gap-3 lg:grid-cols-5" data-test="report-summary">
        <div class="{{ $card }} p-4">
            <flux:text size="sm">{{ __('Occupancy (now)') }}</flux:text>
            <p class="mt-1 text-xl font-semibold tabular-nums text-zinc-900 dark:text-white" data-test="summary-occupancy">{{ $report->occupancy->totals->occupancyPercent() }}%</p>
            <flux:text size="sm">{{ __(':occupied of :total units', ['occupied' => number_format($report->occupancy->totals->occupied), 'total' => number_format($report->occupancy->totals->total())]) }}</flux:text>
        </div>
        <div class="{{ $card }} p-4">
            <flux:text size="sm">{{ __('Billed') }}</flux:text>
            <p class="mt-1 text-xl font-semibold tabular-nums text-zinc-900 dark:text-white" data-test="summary-billed">{{ $report->collections->totals->billedTotal()->format() }}</p>
        </div>
        <div class="{{ $card }} p-4">
            <flux:text size="sm">{{ __('Collected') }}</flux:text>
            <p class="mt-1 text-xl font-semibold tabular-nums text-zinc-900 dark:text-white" data-test="summary-collected">{{ $report->collections->totals->collectedTotal()->format() }}</p>
            <flux:text size="sm" data-test="summary-rate">{{ __('Collection rate :rate', ['rate' => $rate($report->collections->totals->rate())]) }}</flux:text>
        </div>
        <div class="{{ $card }} p-4">
            <flux:text size="sm">{{ __('Owed past due (today)') }}</flux:text>
            <p class="mt-1 text-xl font-semibold tabular-nums text-zinc-900 dark:text-white" data-test="summary-arrears">{{ $report->arrears->total()->format() }}</p>
            <flux:text size="sm">{{ trans_choice(':count tenant|:count tenants', $report->arrears->tenantCount(), ['count' => number_format($report->arrears->tenantCount())]) }}</flux:text>
        </div>
        <div class="{{ $card }} col-span-2 p-4 lg:col-span-1">
            <flux:text size="sm">{{ __('Expenses') }}</flux:text>
            <p class="mt-1 text-xl font-semibold tabular-nums text-zinc-900 dark:text-white" data-test="summary-expenses">{{ $report->expenses->total()->format() }}</p>
        </div>
    </div>

    {{-- Collections vs billed. --}}
    <section class="flex flex-col gap-3" aria-labelledby="report-collections" data-test="report-collections">
        <div class="flex flex-wrap items-end justify-between gap-2">
            <div>
                <flux:heading size="lg" level="2" id="report-collections">{{ __('Collections vs billed') }}</flux:heading>
                <flux:text class="mt-1">{{ __('Bills issued in the period (void bills left out) and the money that settled bills in it: payments when confirmed, tenant credit when used, voided receipts taken back.') }}</flux:text>
            </div>
            <x-list-export list="report_collections" size="sm" />
        </div>
        <div class="{{ $card }} overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="border-b border-zinc-200 dark:border-zinc-700">
                    <tr><th class="{{ $th }}">{{ __('Month') }}</th><th class="{{ $thr }}">{{ __('Billed') }}</th><th class="{{ $thr }}">{{ __('Collected') }}</th><th class="{{ $thr }}">{{ __('Rate') }}</th></tr>
                </thead>
                <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                    @foreach ($report->collections->months as $month)
                        <tr wire:key="month-{{ $month->month->key }}" data-month="{{ $month->month->key }}">
                            <td class="{{ $td }}">{{ $month->month->label }}</td>
                            <td class="{{ $tdr }}">{{ $month->totals->billedTotal()->format() }}</td>
                            <td class="{{ $tdr }}">{{ $month->totals->collectedTotal()->format() }}</td>
                            <td class="{{ $tdr }}">{{ $rate($month->totals->rate()) }}</td>
                        </tr>
                    @endforeach
                </tbody>
                <tbody class="border-t border-zinc-200 dark:border-zinc-700">
                    @foreach ($groups as $group => $label)
                        <tr data-group="{{ $group }}">
                            <td class="{{ $td }} text-zinc-500 dark:text-zinc-400">{{ __($label) }}</td>
                            <td class="{{ $tdr }}">{{ $report->collections->totals->billed[$group]->format() }}</td>
                            <td class="{{ $tdr }}">{{ $report->collections->totals->collected[$group]->format() }}</td>
                            <td class="{{ $tdr }}">{{ $rate($report->collections->totals->rateOf($group)) }}</td>
                        </tr>
                    @endforeach
                    <tr class="font-semibold" data-test="collections-total">
                        <td class="{{ $td }}">{{ __('Total') }}</td>
                        <td class="{{ $tdr }}">{{ $report->collections->totals->billedTotal()->format() }}</td>
                        <td class="{{ $tdr }}">{{ $report->collections->totals->collectedTotal()->format() }}</td>
                        <td class="{{ $tdr }}">{{ $rate($report->collections->totals->rate()) }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </section>

    {{-- Arrears aging. --}}
    <section class="flex flex-col gap-3" aria-labelledby="report-arrears" data-test="report-arrears">
        <div class="flex flex-wrap items-end justify-between gap-2">
            <div>
                <flux:heading size="lg" level="2" id="report-arrears">{{ __('Arrears aging') }}</flux:heading>
                <flux:text class="mt-1">{{ __('What tenants still owe on bills due on or before today (:date), by days past the due date. Not tied to the period.', ['date' => $report->arrears->asOf->format('j M Y')]) }}</flux:text>
            </div>
            <x-list-export list="report_arrears" size="sm" />
        </div>
        <div class="grid grid-cols-2 gap-3 sm:grid-cols-5">
            @foreach ($buckets as $bucket)
                <div class="{{ $card }} p-3" data-bucket="{{ $bucket->value }}">
                    <flux:text size="sm">{{ __($bucket->label()) }}</flux:text>
                    <p class="mt-1 font-semibold tabular-nums text-zinc-900 dark:text-white">{{ $report->arrears->owedIn($bucket)->format() }}</p>
                    <flux:text size="sm">{{ trans_choice(':count bill|:count bills', $report->arrears->counts[$bucket->value], ['count' => number_format($report->arrears->counts[$bucket->value])]) }}</flux:text>
                </div>
            @endforeach
            <div class="{{ $card }} col-span-2 p-3 sm:col-span-1" data-test="not-yet-due">
                <flux:text size="sm">{{ __('Not yet due') }}</flux:text>
                <p class="mt-1 font-semibold tabular-nums text-zinc-500 dark:text-zinc-400">{{ $report->arrears->notYetDue->format() }}</p>
                <flux:text size="sm">{{ trans_choice(':count bill|:count bills', $report->arrears->notYetDueCount, ['count' => number_format($report->arrears->notYetDueCount)]) }}</flux:text>
            </div>
        </div>
        @if ($report->arrears->lines !== [])
            <div class="{{ $card }} overflow-x-auto">
                <table class="w-full text-sm" data-test="arrears-buildings">
                    <thead class="border-b border-zinc-200 dark:border-zinc-700">
                        <tr>
                            <th class="{{ $th }}">{{ __('Building') }}</th>
                            @foreach ($buckets as $bucket)
                                <th class="{{ $thr }}">{{ __($bucket->label()) }}</th>
                            @endforeach
                            <th class="{{ $thr }}">{{ __('Total') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                        @foreach ($report->arrears->buildingRows() as $row)
                            <tr>
                                <td class="{{ $td }}">{{ $row['name'] }}</td>
                                @foreach ($buckets as $bucket)
                                    <td class="{{ $tdr }}">{{ $row['buckets'][$bucket->value]->format() }}</td>
                                @endforeach
                                <td class="{{ $tdr }} font-semibold">{{ $row['total']->format() }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="{{ $card }} overflow-x-auto">
                <table class="w-full text-sm" data-test="arrears-bills">
                    <thead class="border-b border-zinc-200 dark:border-zinc-700">
                        <tr><th class="{{ $th }}">{{ __('Tenant') }}</th><th class="{{ $th }}">{{ __('Bill') }}</th><th class="{{ $th }}">{{ __('Due') }}</th><th class="{{ $thr }}">{{ __('Days') }}</th><th class="{{ $thr }}">{{ __('Owed') }}</th></tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                        @foreach (array_slice($report->arrears->lines, 0, $listed) as $line)
                            <tr wire:key="arrears-{{ $line->bill->id }}" data-bill="{{ $line->bill->id }}">
                                <td class="{{ $td }} min-w-40"><a href="{{ route('workspace.bills.show', [$organization, $line->bill->id]) }}" wire:navigate class="hover:underline">{{ $line->bill->tenantName }}</a><span class="block text-xs text-zinc-500 dark:text-zinc-400">{{ $line->bill->unit !== null ? $line->bill->unit.' · ' : '' }}{{ $line->buildingName }}</span></td>
                                <td class="{{ $td }} whitespace-nowrap">{{ $line->bill->number }}<span class="block text-xs text-zinc-500 dark:text-zinc-400">{{ __($line->bill->typeLabel) }}</span></td>
                                <td class="{{ $td }} whitespace-nowrap">{{ $line->bill->dueDate->format('d/m/Y') }}</td>
                                <td class="{{ $tdr }}">{{ $line->days }}</td>
                                <td class="{{ $tdr }}">{{ $line->bill->outstanding->format() }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                @if (count($report->arrears->lines) > $listed)
                    <flux:text size="sm" class="px-3 py-2">{{ __('The :listed oldest of :count bills; the export lists them all.', ['listed' => $listed, 'count' => number_format(count($report->arrears->lines))]) }}</flux:text>
                @endif
            </div>
        @else
            <flux:text data-test="no-arrears">{{ __('Nothing is owed past due.') }}</flux:text>
        @endif
    </section>

    {{-- Upcoming renewals. --}}
    <section class="flex flex-col gap-3" aria-labelledby="report-renewals" data-test="report-renewals">
        <div class="flex flex-wrap items-end justify-between gap-2">
            <div>
                <flux:heading size="lg" level="2" id="report-renewals">{{ __('Upcoming renewals') }}</flux:heading>
                <flux:text class="mt-1">{{ __('Active leases ending by :date, soonest first, with where each renewal stands.', ['date' => $report->renewals->until->format('j M Y')]) }}</flux:text>
            </div>
            <div class="flex flex-wrap items-end gap-2">
                <flux:select wire:model.live="days" size="sm" :aria-label="__('Look ahead')" class="w-36" data-test="days-filter">
                    @foreach ($windows as $window)
                        <option value="{{ $window === \Modules\Reports\Reports\ReportFilters::DEFAULT_RENEWAL_DAYS ? '' : $window }}">{{ __('Next :days days', ['days' => $window]) }}</option>
                    @endforeach
                </flux:select>
                <x-list-export list="report_renewals" size="sm" />
            </div>
        </div>
        @if ($report->renewals->lines !== [])
            <div class="{{ $card }} overflow-x-auto">
                <table class="w-full text-sm" data-test="renewals">
                    <thead class="border-b border-zinc-200 dark:border-zinc-700">
                        <tr><th class="{{ $th }}">{{ __('Tenant') }}</th><th class="{{ $th }}">{{ __('Ends') }}</th><th class="{{ $thr }}">{{ __('Rent') }}</th><th class="{{ $th }}">{{ __('Renewal') }}</th></tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                        @foreach (array_slice($report->renewals->lines, 0, $listed) as $line)
                            <tr wire:key="renewal-{{ $line->leaseId }}" data-lease="{{ $line->leaseId }}">
                                <td class="{{ $td }} min-w-40"><a href="{{ route('workspace.leases.show', [$organization, $line->leaseId]) }}" wire:navigate class="hover:underline">{{ $line->tenantName }}</a><span class="block text-xs text-zinc-500 dark:text-zinc-400">{{ $line->unitLabel !== '' ? $line->unitLabel.' · ' : '' }}{{ $line->buildingName }}</span></td>
                                <td class="{{ $td }} whitespace-nowrap">{{ $line->endDate->format('d/m/Y') }}<span class="block text-xs text-zinc-500 dark:text-zinc-400">{{ trans_choice('in :count day|in :count days', $line->daysLeft, ['count' => $line->daysLeft]) }}</span></td>
                                <td class="{{ $tdr }}">{{ $line->rent->format() }}<span class="block text-xs text-zinc-500 dark:text-zinc-400">{{ __($line->cycleLabel) }}</span></td>
                                <td class="{{ $td }}"><flux:badge size="sm" :color="match ($line->renewalState) { 'renewal_drafted' => 'green', 'auto_renews' => 'blue', default => 'amber' }">{{ __($line->renewalLabel) }}</flux:badge></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                @if (count($report->renewals->lines) > $listed)
                    <flux:text size="sm" class="px-3 py-2">{{ __('The :listed soonest of :count leases; the export lists them all.', ['listed' => $listed, 'count' => number_format(count($report->renewals->lines))]) }}</flux:text>
                @endif
            </div>
        @else
            <flux:text data-test="no-renewals">{{ __('No active lease ends in the next :days days.', ['days' => $report->renewals->days]) }}</flux:text>
        @endif
    </section>

    {{-- Occupancy. --}}
    <section class="flex flex-col gap-3" aria-labelledby="report-occupancy" data-test="report-occupancy">
        <div class="flex flex-wrap items-end justify-between gap-2">
            <div>
                <flux:heading size="lg" level="2" id="report-occupancy">{{ __('Occupancy') }}</flux:heading>
                <flux:text class="mt-1">{{ __('Units by status now, building by building (archived units left out). Not tied to the period.') }}</flux:text>
            </div>
            <x-list-export list="report_occupancy" size="sm" />
        </div>
        <div class="{{ $card }} overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="border-b border-zinc-200 dark:border-zinc-700">
                    <tr><th class="{{ $th }}">{{ __('Building') }}</th><th class="{{ $thr }}">{{ __('Units') }}</th><th class="{{ $thr }}">{{ __('Occupied') }}</th><th class="{{ $thr }}">{{ __('Vacant') }}</th><th class="{{ $thr }}">{{ __('Other') }}</th><th class="{{ $thr }}">{{ __('Occupancy') }}</th></tr>
                </thead>
                <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                    @forelse ($report->occupancy->buildings as $building)
                        <tr wire:key="occupancy-{{ $building->propertyId }}" data-building="{{ $building->propertyId }}">
                            <td class="{{ $td }}">{{ $building->name }}</td>
                            <td class="{{ $tdr }}">{{ number_format($building->units->total()) }}</td>
                            <td class="{{ $tdr }}">{{ number_format($building->units->occupied) }}</td>
                            <td class="{{ $tdr }}">{{ number_format($building->units->vacant) }}</td>
                            <td class="{{ $tdr }}" title="{{ __('Reserved, under maintenance or unavailable') }}">{{ number_format($building->units->reserved + $building->units->underMaintenance + $building->units->unavailable) }}</td>
                            <td class="{{ $tdr }}">{{ $building->units->occupancyPercent() }}%</td>
                        </tr>
                    @empty
                        <tr><td class="{{ $td }} text-zinc-500" colspan="6">{{ __('No buildings yet.') }}</td></tr>
                    @endforelse
                </tbody>
                <tbody class="border-t border-zinc-200 font-semibold dark:border-zinc-700">
                    <tr data-test="occupancy-total">
                        <td class="{{ $td }}">{{ __('Total') }}</td>
                        <td class="{{ $tdr }}">{{ number_format($report->occupancy->totals->total()) }}</td>
                        <td class="{{ $tdr }}">{{ number_format($report->occupancy->totals->occupied) }}</td>
                        <td class="{{ $tdr }}">{{ number_format($report->occupancy->totals->vacant) }}</td>
                        <td class="{{ $tdr }}">{{ number_format($report->occupancy->totals->reserved + $report->occupancy->totals->underMaintenance + $report->occupancy->totals->unavailable) }}</td>
                        <td class="{{ $tdr }}">{{ $report->occupancy->totals->occupancyPercent() }}%</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </section>

    {{-- Expenses by category. --}}
    <section class="flex flex-col gap-3" aria-labelledby="report-expenses" data-test="report-expenses">
        <div class="flex flex-wrap items-end justify-between gap-2">
            <div>
                <flux:heading size="lg" level="2" id="report-expenses">{{ __('Expenses by category') }}</flux:heading>
                <flux:text class="mt-1">{{ $report->expenses->includesOrganization ? __('Expenses paid in the period (archived ones left out), on buildings and the organization\'s own.') : __('Expenses paid in the period on these buildings (archived ones left out).') }}</flux:text>
            </div>
            <x-list-export list="report_expenses" size="sm" />
        </div>
        @if ($report->expenses->categories !== [])
            <div class="{{ $card }} overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="border-b border-zinc-200 dark:border-zinc-700">
                        <tr><th class="{{ $th }}">{{ __('Category') }}</th><th class="{{ $thr }}">{{ __('Expenses') }}</th><th class="{{ $thr }}">{{ __('On buildings') }}</th>@if ($report->expenses->includesOrganization)<th class="{{ $thr }}">{{ __('Organization\'s own') }}</th>@endif<th class="{{ $thr }}">{{ __('Total') }}</th></tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                        @foreach ($report->expenses->categories as $line)
                            <tr data-category="{{ $line->category }}">
                                <td class="{{ $td }}">{{ __($line->label) }}</td>
                                <td class="{{ $tdr }}">{{ number_format($line->count) }}</td>
                                <td class="{{ $tdr }}">{{ $line->buildings->format() }}</td>
                                @if ($report->expenses->includesOrganization)<td class="{{ $tdr }}">{{ $line->organization->format() }}</td>@endif
                                <td class="{{ $tdr }} font-semibold">{{ $line->total()->format() }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @else
            <flux:text data-test="no-expenses">{{ __('No expenses paid in the period.') }}</flux:text>
        @endif
    </section>

    {{-- Per-building performance. --}}
    <section class="flex flex-col gap-3" aria-labelledby="report-buildings" data-test="report-buildings">
        <div class="flex flex-wrap items-end justify-between gap-2">
            <div>
                <flux:heading size="lg" level="2" id="report-buildings">{{ __('Building performance') }}</flux:heading>
                <flux:text class="mt-1">{{ __('Billed, collected and spent in the period; units and what is owed past due as of today.') }}</flux:text>
            </div>
            <x-list-export list="report_buildings" size="sm" />
        </div>
        <div class="{{ $card }} overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="border-b border-zinc-200 dark:border-zinc-700">
                    <tr><th class="{{ $th }}">{{ __('Building') }}</th><th class="{{ $thr }}">{{ __('Occupancy') }}</th><th class="{{ $thr }}">{{ __('Billed') }}</th><th class="{{ $thr }}">{{ __('Collected') }}</th><th class="{{ $thr }}">{{ __('Rate') }}</th><th class="{{ $thr }}">{{ __('Owed past due') }}</th><th class="{{ $thr }}">{{ __('Expenses') }}</th><th class="{{ $thr }}">{{ __('Collected less expenses') }}</th></tr>
                </thead>
                <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                    @forelse ($report->buildings->lines as $line)
                        <tr wire:key="building-{{ $line->propertyId ?? 'none' }}" data-performance="{{ $line->propertyId ?? 'none' }}">
                            <td class="{{ $td }} min-w-40">{{ $line->name }}@if ($line->propertyId !== null && ! $line->active) <span class="text-xs text-zinc-500 dark:text-zinc-400">{{ __('(no longer active)') }}</span>@endif</td>
                            <td class="{{ $tdr }}">{{ $line->units->total() > 0 ? $line->units->occupancyPercent().'%' : '—' }}</td>
                            <td class="{{ $tdr }}">{{ $line->flows->billedTotal()->format() }}</td>
                            <td class="{{ $tdr }}">{{ $line->flows->collectedTotal()->format() }}</td>
                            <td class="{{ $tdr }}">{{ $rate($line->flows->rate()) }}</td>
                            <td class="{{ $tdr }}">{{ $line->arrears->format() }}</td>
                            <td class="{{ $tdr }}">{{ $line->expenses->format() }}</td>
                            <td class="{{ $tdr }} font-semibold">{{ $line->net()->format() }}</td>
                        </tr>
                    @empty
                        <tr><td class="{{ $td }} text-zinc-500" colspan="8">{{ __('No buildings yet.') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
</x-page>
