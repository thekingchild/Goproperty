@php
    use App\Enums\OrganizationType;
    use App\Enums\SubscriptionStatus;
    use App\Enums\TransactionTier;
    use App\Platform\Analytics\AnalyticsCsv;
    use App\Platform\Analytics\AnalyticsRange;
    use App\Platform\Analytics\CollectionChannel;
    use App\Platform\Analytics\PlatformAnalytics as A;
    use App\Platform\Analytics\PlatformMetric as M;
    use App\Platform\Filament\Resources\Customers\CustomerResource;
    use App\Support\Money;

    /** @var A $analytics */
    /** @var AnalyticsRange $range */
    $money = static fn (int $kobo): string => Money::kobo($kobo)->format();
    $number = static fn (int $value): string => number_format($value);
    $orDash = static fn (?int $value, Closure $format): string => $value === null ? '—' : $format($value);
    $csv = static fn (string $section): string => route('filament.console.analytics-export', ['section' => $section, ...$range->query()]);
    $grid = 'display:grid;grid-template-columns:repeat(auto-fit,minmax(11rem,1fr));gap:.75rem';
    $tile = 'padding:.75rem;border:1px solid rgba(127,127,127,.25);border-radius:.5rem';
    $tileLabel = 'font-size:.8125rem;opacity:.75';
    $tileValue = 'font-size:1.375rem;font-weight:600;margin-top:.125rem';
    $tileNote = 'font-size:.75rem;opacity:.7;margin-top:.125rem';
    $table = 'width:100%;border-collapse:collapse;font-size:.875rem';
    $th = 'padding:.4rem .5rem;text-align:left;font-weight:600';
    $td = 'padding:.4rem .5rem;text-align:left';
    $num = 'padding:.4rem .5rem;text-align:right;white-space:nowrap';
    $row = 'border-top:1px solid rgba(127,127,127,.2)';
    $note = 'font-size:.8125rem;opacity:.8;margin:.75rem 0 0';
    $download = static fn (string $section) => new Illuminate\Support\HtmlString('<a href="'.e($csv($section)).'" data-csv="'.e($section).'" style="font-size:.875rem;font-weight:600;color:var(--primary-600);white-space:nowrap">Download CSV</a>');
    $stocksDay = $analytics->stocksDay();
    $missing = count($analytics->missingDays());
@endphp

<x-filament-panels::page>
    <x-filament::section>
        <div style="display:flex;flex-wrap:wrap;gap:.75rem;align-items:flex-end">
            <label style="display:flex;flex-direction:column;gap:.25rem;font-size:.875rem">
                <span>Period</span>
                <x-filament::input.wrapper>
                    <x-filament::input.select wire:model.live="preset" data-filter="range">
                        @foreach (AnalyticsRange::PRESETS as $key => $label)
                            <option value="{{ $key }}">{{ $label }}</option>
                        @endforeach
                    </x-filament::input.select>
                </x-filament::input.wrapper>
            </label>
            <label style="display:flex;flex-direction:column;gap:.25rem;font-size:.875rem">
                <span>From</span>
                <x-filament::input.wrapper>
                    <x-filament::input type="date" wire:model.live.blur="from" data-filter="from" max="{{ $range->to->toDateString() }}" />
                </x-filament::input.wrapper>
            </label>
            <label style="display:flex;flex-direction:column;gap:.25rem;font-size:.875rem">
                <span>To</span>
                <x-filament::input.wrapper>
                    <x-filament::input type="date" wire:model.live.blur="to" data-filter="to" max="{{ now('Africa/Lagos')->subDay()->toDateString() }}" />
                </x-filament::input.wrapper>
            </label>
        </div>
        <p style="{{ $note }}">
            <span data-range>{{ $range->label() }}</span>.
            @if ($stocksDay !== null)
                Counts and amounts that stand at a moment (MRR, customers, units) are as of the end of <span data-stocks-day>{{ $stocksDay->format('j M Y') }}</span>.
            @endif
            @if ($missing > 0 && $analytics->hasData())
                <span data-missing-days>{{ $number($missing) }}</span> {{ $missing === 1 ? 'day' : 'days' }} of the period {{ $missing === 1 ? 'has' : 'have' }} no figures (before the first nightly summary, or a night it did not run).
            @endif
        </p>
    </x-filament::section>

    @if (! $analytics->hasData())
        <x-filament::section>
            <p data-empty>No figures for this period yet. The nightly summary runs just after each Lagos day ends (scheduler task <code>metrics-snapshot</code>; by hand: <code>php artisan metrics:snapshot</code>).</p>
        </x-filament::section>
    @else
        {{-- Revenue --}}
        <x-filament::section heading="Revenue" description="GoProperty's own income from customers. Platform-covered organizations (landlords) pay nothing and are left out.">
            <x-slot name="afterHeader">{{ $download('revenue') }}</x-slot>
            @php
                $mrr = $analytics->latest(M::MrrKobo);
                $mrrBefore = $analytics->start(M::MrrKobo);
                $months = $analytics->revenueByMonth();
                $averageDays = $analytics->averageDaysToPay();
            @endphp
            <div style="{{ $grid }}">
                <div style="{{ $tile }}">
                    <div style="{{ $tileLabel }}">MRR</div>
                    <div style="{{ $tileValue }}" data-figure="mrr">{{ $orDash($mrr, $money) }}</div>
                    @if ($mrr !== null && $mrrBefore !== null)
                        <div style="{{ $tileNote }}"><span data-figure="mrr_change">{{ ($mrr - $mrrBefore) >= 0 ? '+' : '−' }}{{ $money(abs($mrr - $mrrBefore)) }}</span> over the period</div>
                    @endif
                </div>
                <div style="{{ $tile }}">
                    <div style="{{ $tileLabel }}">ARR</div>
                    <div style="{{ $tileValue }}" data-figure="arr">{{ $orDash($analytics->arr(), $money) }}</div>
                    <div style="{{ $tileNote }}"><span data-figure="paying_customers">{{ $orDash($analytics->latest(M::PayingCustomers), $number) }}</span> paying customers</div>
                </div>
                <div style="{{ $tile }}">
                    <div style="{{ $tileLabel }}">Invoiced in {{ $months['thisMonth']->format('F Y') }}</div>
                    <div style="{{ $tileValue }}" data-figure="revenue_this_month">{{ $money($months['this']) }}</div>
                    <div style="{{ $tileNote }}"><span data-figure="revenue_last_month">{{ $money($months['last']) }}</span> in {{ $months['lastMonth']->format('F') }}</div>
                </div>
                <div style="{{ $tile }}">
                    <div style="{{ $tileLabel }}">Collection rate</div>
                    <div style="{{ $tileValue }}" data-figure="collection_rate">{{ A::percent($analytics->collectionRate()) }}</div>
                    <div style="{{ $tileNote }}"><span data-figure="received">{{ $money($analytics->sum(M::ReceivedKobo)) }}</span> received of <span data-figure="invoiced">{{ $money($analytics->sum(M::InvoicedKobo)) }}</span> invoiced (VAT included)</div>
                </div>
                <div style="{{ $tile }}">
                    <div style="{{ $tileLabel }}">Average days to pay</div>
                    <div style="{{ $tileValue }}" data-figure="days_to_pay">{{ $averageDays === null ? '—' : intdiv($averageDays, 10).'.'.($averageDays % 10) }}</div>
                    <div style="{{ $tileNote }}"><span data-figure="invoices_paid">{{ $number($analytics->sum(M::InvoicesPaid)) }}</span> invoices paid in the period</div>
                </div>
                <div style="{{ $tile }}">
                    <div style="{{ $tileLabel }}">Outstanding invoices</div>
                    <div style="{{ $tileValue }}" data-figure="outstanding">{{ $orDash($analytics->latest(M::OutstandingKobo), $money) }}</div>
                    <div style="{{ $tileNote }}"><span data-figure="outstanding_invoices">{{ $orDash($analytics->latest(M::OutstandingInvoices), $number) }}</span> open, <span data-figure="overdue">{{ $orDash($analytics->latest(M::OverdueKobo), $money) }}</span> overdue</div>
                </div>
            </div>
            <div style="{{ $grid }};margin-top:1rem">
                @include('console.analytics.bars', ['chart' => 'mrr', 'points' => $analytics->daily(M::MrrKobo), 'format' => $money, 'title' => 'MRR by day'])
                @include('console.analytics.bars', ['chart' => 'invoiced_by_month', 'points' => $analytics->monthly(M::InvoicedNetKobo), 'format' => $money, 'title' => 'Invoiced before VAT, by month'])
            </div>
            @php $byPlan = $analytics->latestByDimension(M::MrrKobo); @endphp
            @if ($byPlan !== [])
                <div style="overflow-x:auto;margin-top:1rem">
                    <table style="{{ $table }}">
                        <thead><tr><th style="{{ $th }}">Plan</th><th style="{{ $num }}">Paying customers</th><th style="{{ $num }}">MRR</th><th style="{{ $num }}">Per customer</th></tr></thead>
                        <tbody>
                            @foreach ($byPlan as $plan => $amount)
                                @php $customers = $analytics->latest(M::PayingCustomers, (string) $plan) ?? 0; @endphp
                                <tr style="{{ $row }}" data-plan="{{ $plan }}">
                                    <td style="{{ $td }}">{{ $analytics->planName((string) $plan) }}</td>
                                    <td style="{{ $num }}">{{ $number($customers) }}</td>
                                    <td style="{{ $num }}">{{ $money($amount) }}</td>
                                    <td style="{{ $num }}">{{ $customers > 0 ? Money::kobo($amount)->dividedBy($customers)->format() : '—' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
            <p style="{{ $note }}">MRR: each paying customer's (active, payment overdue or final days) monthly price at its plan's terms today for its units now, before VAT; yearly plans count as a twelfth of their 10 months' price. ARR is 12 × MRR. Invoiced: GoProperty's invoices issued, void ones left out. Collection rate: money received in the period ÷ invoiced in the period, so it can pass 100%. Days to pay: from an invoice's issue to its payment, Lagos days.</p>
        </x-filament::section>

        {{-- Customers --}}
        <x-filament::section heading="Customers" description="Organizations that pay GoProperty, by the state of their subscription. Platform-covered organizations are counted apart.">
            <x-slot name="afterHeader">{{ $download('customers') }}</x-slot>
            <div style="{{ $grid }}">
                <div style="{{ $tile }}">
                    <div style="{{ $tileLabel }}">Signups</div>
                    <div style="{{ $tileValue }}" data-figure="signups">{{ $number($analytics->sum(M::Signups)) }}</div>
                    <div style="{{ $tileNote }}">
                        @foreach (OrganizationType::cases() as $type)
                            <span data-figure="signups_{{ $type->value }}">{{ $number($analytics->sum(M::Signups, $type->value)) }}</span> {{ strtolower($type->label()) }}@if (! $loop->last), @endif
                        @endforeach
                    </div>
                </div>
                <div style="{{ $tile }}">
                    <div style="{{ $tileLabel }}">Trial → paid</div>
                    <div style="{{ $tileValue }}" data-figure="trial_conversion">{{ A::percent($analytics->trialConversion()) }}</div>
                    <div style="{{ $tileNote }}"><span data-figure="trial_conversions">{{ $number($analytics->sum(M::TrialConversions)) }}</span> first payments, <span data-figure="trials_ended">{{ $number($analytics->sum(M::TrialsEnded)) }}</span> trials ended</div>
                </div>
                <div style="{{ $tile }}">
                    <div style="{{ $tileLabel }}">Churn</div>
                    <div style="{{ $tileValue }}" data-figure="churn_rate">{{ A::percent($analytics->churnRate()) }}</div>
                    <div style="{{ $tileNote }}"><span data-figure="cancellations">{{ $number($analytics->sum(M::Cancellations)) }}</span> paying customers cancelled of <span data-figure="paying_at_start">{{ $orDash($analytics->start(M::PayingCustomers), $number) }}</span> at the start</div>
                </div>
                <div style="{{ $tile }}">
                    <div style="{{ $tileLabel }}">Trials cancelled</div>
                    <div style="{{ $tileValue }}" data-figure="trial_cancellations">{{ $number($analytics->sum(M::TrialCancellations)) }}</div>
                    <div style="{{ $tileNote }}">before paying (not churn)</div>
                </div>
            </div>
            <div style="margin-top:1rem">
                @include('console.analytics.bars', ['chart' => 'signups_by_week', 'points' => $analytics->weekly(M::Signups), 'format' => $number, 'title' => 'Signups per week (weeks start on Monday)'])
            </div>
            <div style="{{ $grid }};margin-top:1rem;align-items:start">
                <div style="overflow-x:auto">
                    <table style="{{ $table }}">
                        <thead><tr><th style="{{ $th }}">Subscription</th><th style="{{ $num }}">Customers</th></tr></thead>
                        <tbody>
                            @foreach (SubscriptionStatus::cases() as $status)
                                <tr style="{{ $row }}"><td style="{{ $td }}">{{ $status->label() }}</td><td style="{{ $num }}" data-figure="status_{{ $status->value }}">{{ $orDash($analytics->latest(M::CustomersByStatus, $status->value), $number) }}</td></tr>
                            @endforeach
                            <tr style="{{ $row }}"><td style="{{ $td }}">No plan chosen yet</td><td style="{{ $num }}" data-figure="status_none">{{ $orDash($analytics->latest(M::CustomersByStatus, 'none'), $number) }}</td></tr>
                            <tr style="{{ $row }}"><th style="{{ $th }}">All customers</th><th style="{{ $num }}" data-figure="customers">{{ $orDash($analytics->latest(M::CustomersByStatus), $number) }}</th></tr>
                            <tr style="{{ $row }}"><td style="{{ $td }}">Platform-covered (not customers)</td><td style="{{ $num }}" data-figure="covered">{{ $orDash($analytics->latest(M::CoveredOrganizations), $number) }}</td></tr>
                        </tbody>
                    </table>
                </div>
                <div style="overflow-x:auto">
                    @php $states = $analytics->latestByDimension(M::OrganizationsByState); @endphp
                    <table style="{{ $table }}">
                        <thead><tr><th style="{{ $th }}">State (open customers)</th><th style="{{ $num }}">Customers</th></tr></thead>
                        <tbody>
                            @forelse (array_slice($states, 0, 12, true) as $state => $count)
                                <tr style="{{ $row }}" data-state="{{ $state }}"><td style="{{ $td }}">{{ $state === 'unknown' ? 'Not given' : $state }}</td><td style="{{ $num }}">{{ $number($count) }}</td></tr>
                            @empty
                                <tr style="{{ $row }}"><td style="{{ $td }}" colspan="2">None yet.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                    @if (count($states) > 12)
                        <p style="{{ $note }}">The 12 largest of {{ count($states) }} states. <a href="{{ $csv('states') }}" data-csv="states" style="font-weight:600;color:var(--primary-600)">Every state as CSV</a>.</p>
                    @else
                        <p style="{{ $note }}"><a href="{{ $csv('states') }}" data-csv="states" style="font-weight:600;color:var(--primary-600)">Download CSV</a></p>
                    @endif
                </div>
            </div>
            <p style="{{ $note }}">Trial → paid: first payments of a subscription invoice in the period ÷ trials that ended in the period. Churn: paying customers whose subscription was cancelled in the period ÷ paying customers at its start; trials cancelled before any payment are counted apart.</p>
        </x-filament::section>

        {{-- Usage --}}
        <x-filament::section heading="Usage" description="Across every organization still open.">
            <x-slot name="afterHeader">{{ $download('usage') }}</x-slot>
            <div style="{{ $grid }}">
                <div style="{{ $tile }}">
                    <div style="{{ $tileLabel }}">Units</div>
                    <div style="{{ $tileValue }}" data-figure="units">{{ $orDash($analytics->latest(M::UnitsTotal), $number) }}</div>
                    <div style="{{ $tileNote }}"><span data-figure="units_added">{{ $number($analytics->sum(M::UnitsAdded)) }}</span> added in the period</div>
                </div>
                <div style="{{ $tile }}">
                    <div style="{{ $tileLabel }}">People signed in</div>
                    <div style="{{ $tileValue }}" data-figure="active_day">{{ $orDash($analytics->lastDay(M::ActiveUsersDay), $number) }}</div>
                    <div style="{{ $tileNote }}">on the last day; <span data-figure="active_week">{{ $orDash($analytics->lastDay(M::ActiveUsersWeek), $number) }}</span> in its last 7 days</div>
                </div>
                <div style="{{ $tile }}">
                    <div style="{{ $tileLabel }}">Tenants with portal access</div>
                    <div style="{{ $tileValue }}" data-figure="portal_tenants">{{ $orDash($analytics->latest(M::PortalTenants), $number) }}</div>
                </div>
                <div style="{{ $tile }}">
                    <div style="{{ $tileLabel }}">Tickets</div>
                    <div style="{{ $tileValue }}"><span data-figure="tickets_opened">{{ $number($analytics->sum(M::TicketsOpened)) }}</span> opened</div>
                    <div style="{{ $tileNote }}"><span data-figure="tickets_resolved">{{ $number($analytics->sum(M::TicketsResolved)) }}</span> resolved in the period</div>
                </div>
            </div>
            <div style="{{ $grid }};margin-top:1rem">
                @include('console.analytics.bars', ['chart' => 'active_users', 'points' => $analytics->daily(M::ActiveUsersDay), 'format' => $number, 'title' => 'People signed in, by day'])
                @include('console.analytics.bars', ['chart' => 'units', 'points' => $analytics->daily(M::UnitsTotal), 'format' => $number, 'title' => 'Units, by day'])
            </div>
            <p style="{{ $note }}">Units are counted as GoProperty bills them: not deleted, in an active building. People signed in: customers' users (not GoProperty staff) who signed in with a password or passkey; someone who stays signed in is not counted again.</p>
        </x-filament::section>

        {{-- Transaction Tiers --}}
        <x-filament::section heading="Transaction Tiers">
            <x-slot name="afterHeader">{{ $download('tiers') }}</x-slot>
            <div style="{{ $grid }}">
                @foreach (TransactionTier::cases() as $tier)
                    <div style="{{ $tile }}">
                        <div style="{{ $tileLabel }}">{{ $tier->label() }}</div>
                        <div style="{{ $tileValue }}" data-figure="tier_{{ $tier->value }}">{{ $orDash($analytics->latest(M::OrganizationsByTier, (string) $tier->value), $number) }}</div>
                        <div style="{{ $tileNote }}">organizations</div>
                    </div>
                @endforeach
                <div style="{{ $tile }}">
                    <div style="{{ $tileLabel }}">Upgrade applications</div>
                    <div style="{{ $tileValue }}"><span data-figure="applications_pending">{{ $orDash($analytics->latest(M::TierApplicationsPending), $number) }}</span> waiting</div>
                    <div style="{{ $tileNote }}"><span data-figure="applications_approved">{{ $number($analytics->sum(M::TierApplicationsApproved)) }}</span> approved, <span data-figure="applications_rejected">{{ $number($analytics->sum(M::TierApplicationsRejected)) }}</span> rejected in the period</div>
                </div>
                <div style="{{ $tile }}">
                    <div style="{{ $tileLabel }}">Open compliance flags</div>
                    <div style="{{ $tileValue }}" data-figure="flags_open">{{ $orDash($analytics->latest(M::ComplianceFlagsOpen), $number) }}</div>
                </div>
            </div>
        </x-filament::section>

        {{-- Collections --}}
        <x-filament::section heading="Collections recorded" description="Tenant payments agencies recorded and confirmed in GoProperty. The money went to the agencies' own accounts; GoProperty never handled it.">
            <x-slot name="afterHeader">{{ $download('collections') }}</x-slot>
            @php $months = array_keys($analytics->monthly(M::CollectionsKobo)); @endphp
            <div style="{{ $grid }}">
                <div style="{{ $tile }}">
                    <div style="{{ $tileLabel }}">Recorded in the period</div>
                    <div style="{{ $tileValue }}" data-figure="collections">{{ $money($analytics->sum(M::CollectionsKobo)) }}</div>
                    <div style="{{ $tileNote }}"><span data-figure="collections_count">{{ $number($analytics->sum(M::Collections)) }}</span> payments</div>
                </div>
            </div>
            <div style="overflow-x:auto;margin-top:1rem">
                <table style="{{ $table }}">
                    <thead>
                        <tr>
                            <th style="{{ $th }}">Month</th>
                            @foreach (CollectionChannel::cases() as $channel)
                                <th style="{{ $num }}">{{ $channel->label() }}</th>
                            @endforeach
                            <th style="{{ $num }}">Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($months as $month)
                            <tr style="{{ $row }}">
                                <td style="{{ $td }}">{{ Carbon\CarbonImmutable::parse($month.'-01')->format('M Y') }}</td>
                                @foreach (CollectionChannel::cases() as $channel)
                                    <td style="{{ $num }}" data-figure="collections_{{ $month }}_{{ $channel->value }}">{{ $money($analytics->monthly(M::CollectionsKobo, $channel->value)[$month] ?? 0) }}</td>
                                @endforeach
                                <td style="{{ $num }}" data-figure="collections_{{ $month }}">{{ $money($analytics->monthly(M::CollectionsKobo)[$month] ?? 0) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <p style="{{ $note }}">Counted when confirmed (Lagos day). Bank statement match, Paystack (the agency's own account), proof from the tenant, cash or POS, and transfers a member recorded by hand. Imported payment history is left out.</p>
        </x-filament::section>

        {{-- Leaderboard --}}
        <x-filament::section heading="Leaderboard" description="Top customers (platform-covered organizations apart).">
            <x-slot name="afterHeader">{{ $download('leaderboard') }}</x-slot>
            @php
                $boards = $analytics->leaderboard();
                $titles = ['units' => 'By units', 'growth' => 'By units added in the period', 'reliability' => 'By payment reliability'];
            @endphp
            <div style="{{ $grid }};grid-template-columns:repeat(auto-fit,minmax(17rem,1fr));align-items:start">
                @foreach ($boards as $board => $entries)
                    <div style="overflow-x:auto" data-board="{{ $board }}">
                        <table style="{{ $table }}">
                            <thead>
                                <tr><th style="{{ $th }}" colspan="2">{{ $titles[$board] }}</th><th style="{{ $num }}">{{ $board === 'reliability' ? 'On time' : ($board === 'growth' ? 'Added' : 'Units') }}</th></tr>
                            </thead>
                            <tbody>
                                @forelse ($entries as $entry)
                                    <tr style="{{ $row }}" data-leader="{{ $entry->organizationId }}">
                                        <td style="{{ $td }};width:1.5rem;opacity:.7">{{ $loop->iteration }}</td>
                                        <td style="{{ $td }}">
                                            @if ($canOpenCustomers)
                                                <a href="{{ CustomerResource::getUrl('view', ['record' => $entry->organizationId]) }}" style="color:var(--primary-600)">{{ $entry->name }}</a>
                                            @else
                                                {{ $entry->name }}
                                            @endif
                                        </td>
                                        <td style="{{ $num }}">
                                            @if ($board === 'reliability')
                                                {{ A::percent($entry->reliability()) }} <span style="opacity:.7">({{ $entry->paidOnTime }}/{{ $entry->invoicesDue }})</span>
                                            @elseif ($board === 'growth')
                                                +{{ $number($entry->growth) }}
                                            @else
                                                {{ $number($entry->units) }}
                                            @endif
                                        </td>
                                    </tr>
                                @empty
                                    <tr style="{{ $row }}"><td style="{{ $td }}" colspan="3">None yet.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                @endforeach
            </div>
            <p style="{{ $note }}">Units as GoProperty bills them, on the last day. Units added: from the day before the period to its last day. Reliability: subscription invoices due in the year to the last day paid by their due date; customers with at least {{ A::RELIABILITY_MIN_INVOICES }} such invoices.</p>
        </x-filament::section>
    @endif
</x-filament-panels::page>
