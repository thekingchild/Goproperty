<?php

declare(strict_types=1);

use App\Platform\Analytics\AnalyticsRange;
use App\Platform\Analytics\MetricsSnapshot;
use App\Platform\Analytics\SnapshotRunner;
use App\Platform\Filament\Pages\SystemAnalytics;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;

require_once __DIR__.'/../../Support/plans.php';
require_once __DIR__.'/analytics-world.php';

/*
 * P08-02 acceptance: "Metrics match a fixture; nightly job is idempotent"
 * (PLATFORM_CONSOLE.md § 3; docs/plans/P08-subtasks.md "Defaults taken in
 * P08-02"). The world is tests/Feature/Console/analytics-world.php, worked
 * by hand: every expected figure below is written out, not computed by the
 * code under test.
 */

beforeEach(function () {
    Filament::setCurrentPanel(Filament::getPanel('console'));
    seedStaffRoles();
    $this->travelTo(CarbonImmutable::parse(SA_NOW, 'UTC'));
});

/** @return array<string, int> "metric|dimension" => value, the day's figures but the snapshot markers */
function saRows(string $date = SA_DAY): array
{
    return DB::table('daily_platform_metrics')->where('date', $date)->where('metric', '!=', 'snapshot')->get()
        ->mapWithKeys(fn (object $row): array => [$row->metric.'|'.$row->dimension => (int) $row->value])->all();
}

/** @return array<string, string> data-figure => text */
function saFigures(string $html): array
{
    preg_match_all('/data-figure="([^"]+)"[^>]*>([^<]*)</', $html, $matches, PREG_SET_ORDER);

    return collect($matches)->mapWithKeys(fn (array $m): array => [$m[1] => trim(html_entity_decode($m[2]))])->all();
}

function saMoney(int $kobo): string
{
    return Money::kobo($kobo)->format();
}

/** The page for one range, as a staff member. */
function saPage(array $query = ['range' => 'custom', 'from' => SA_DAY, 'to' => SA_DAY]): string
{
    return Livewire::withQueryParams($query)->test(SystemAnalytics::class)->assertOk()->html();
}

/** The figures the fixture's 1 March 2027 must give, by hand. */
function saExpected(): array
{
    return [
        // Flows of 1 March (Lagos).
        'invoiced_kobo|' => 1_623_250, 'invoiced_kobo|subscription' => 1_300_750, 'invoiced_kobo|sms_credits' => 322_500,
        'invoiced_net_kobo|' => 1_510_000, 'invoiced_net_kobo|subscription' => 1_210_000, 'invoiced_net_kobo|sms_credits' => 300_000,
        'received_kobo|' => 6_030_750,
        'refunded_kobo|' => 50_000,
        'invoices_paid|' => 3, 'days_to_pay|' => 23,
        'signups|' => 2, 'signups|agency' => 1, 'signups|property_owner' => 1,
        'trials_ended|' => 1, 'trial_conversions|' => 1,
        'cancellations|' => 1, 'trial_cancellations|' => 1,
        'units_added|' => 1,
        'active_users_1d|' => 2, 'active_users_7d|' => 3,
        'tickets_opened|' => 2, 'tickets_resolved|' => 1,
        'tier_applications_approved|' => 1, 'tier_applications_rejected|' => 1,
        'collections_kobo|' => 970_000, 'collections|' => 6,
        'collections_kobo|bank_match' => 500_000, 'collections|bank_match' => 1,
        'collections_kobo|paystack' => 250_000, 'collections|paystack' => 1,
        'collections_kobo|proof' => 100_000, 'collections|proof' => 1,
        'collections_kobo|cash' => 50_000, 'collections|cash' => 2,
        'collections_kobo|transfer' => 70_000, 'collections|transfer' => 1,
        // Stocks at 05:00 on 2 March.
        // A: ₦10,000 + 3 × ₦700 = ₦12,100; K: ₦10,000; B yearly: (₦35,000 + 10 × ₦500) × 10 ÷ 12 = ₦33,333.33.
        'mrr_kobo|' => 5_543_333, 'mrr_kobo|starter' => 2_210_000, 'mrr_kobo|growth' => 3_333_333,
        'paying_customers|' => 3, 'paying_customers|starter' => 2, 'paying_customers|growth' => 1,
        'outstanding_kobo|' => 4_837_500, 'outstanding_invoices|' => 2, 'overdue_kobo|' => 4_515_000,
        'customers_by_status|' => 7, 'customers_by_status|active' => 2, 'customers_by_status|past_due' => 1,
        'customers_by_status|trialing' => 1, 'customers_by_status|cancelled' => 2, 'customers_by_status|none' => 1,
        'covered_organizations|' => 2,
        'organizations_by_state|' => 5, 'organizations_by_state|Lagos' => 3, 'organizations_by_state|Oyo' => 1, 'organizations_by_state|Kano' => 1,
        'units_total|' => 90,
        'portal_tenants|' => 2,
        'organizations_by_tier|' => 7, 'organizations_by_tier|1' => 6, 'organizations_by_tier|2' => 1,
        'tier_applications_pending|' => 2,
        'compliance_flags_open|' => 2,
    ];
}

/** Per-organization stocks, keyed by letter. */
function saExpectedPerOrganization(array $o): array
{
    return [
        'org_units|'.$o['A'] => 13, 'org_units|'.$o['B'] => 60, 'org_units|'.$o['C'] => 5, 'org_units|'.$o['K'] => 8,
        'org_invoices_due|'.$o['A'] => 3, 'org_invoices_on_time|'.$o['A'] => 2,
        'org_invoices_due|'.$o['B'] => 3, 'org_invoices_on_time|'.$o['B'] => 1,
        'org_invoices_due|'.$o['J'] => 1, 'org_invoices_on_time|'.$o['J'] => 1,
    ];
}

it('uses the launch prices the fixture was worked with', function () {
    expect(planNamed('starter')->priceFor(13)->kobo)->toBe(1_210_000)
        ->and(planNamed('growth')->priceFor(60)->kobo)->toBe(4_000_000)
        ->and(catalogue()->monthsPaidPerYear())->toBe(10);
});

it('acceptance: the nightly snapshot of 1 March 2027 matches the hand-computed fixture', function () {
    $o = analyticsWorld();

    expect(app(SnapshotRunner::class)->nightly())->toBe([SA_DAY])
        ->and(saRows())->toEqual([...saExpected(), ...saExpectedPerOrganization($o)]);

    $markers = DB::table('daily_platform_metrics')->where('date', SA_DAY)->where('metric', 'snapshot')->pluck('value', 'dimension')->all();
    expect($markers)->toEqual(['flows' => CarbonImmutable::parse(SA_NOW, 'UTC')->getTimestamp(), 'stocks' => CarbonImmutable::parse(SA_NOW, 'UTC')->getTimestamp()]);
});

it('acceptance: the nightly job is idempotent — running it again leaves the same rows, never a second set', function () {
    $o = analyticsWorld();
    $runner = app(SnapshotRunner::class);
    $dump = fn (): array => DB::table('daily_platform_metrics')->orderBy('date')->orderBy('metric')->orderBy('dimension')
        ->get(['date', 'metric', 'dimension', 'value'])->map(fn (object $row): array => (array) $row)->all();

    $runner->nightly();
    $first = $dump();

    // The scheduler's later runs that night find the day taken.
    expect($runner->nightly())->toBe([]);
    $this->travel(5)->hours();
    expect($runner->nightly())->toBe([])->and($dump())->toEqual($first);

    // Taken again by hand, twice: replaced as a whole, same figures, same count.
    $this->travelTo(CarbonImmutable::parse(SA_NOW, 'UTC'));
    app(MetricsSnapshot::class)->take(CarbonImmutable::parse(SA_DAY, 'Africa/Lagos'), withStocks: true);
    app(MetricsSnapshot::class)->take(CarbonImmutable::parse(SA_DAY, 'Africa/Lagos'), withStocks: true);
    expect($dump())->toEqual($first)
        ->and(DB::table('daily_platform_metrics')->count())->toBe(count($first));

    // Records changed since: a re-run writes the new figures and leaves no stale dimension behind.
    DB::table('platform_invoices')->where('kind', 'sms_credits')->update(['status' => 'void']);
    DB::table('payments')->where('source', 'proof_upload')->update(['deleted_at' => SA_NOW]);
    $this->artisan('metrics:snapshot', ['date' => SA_DAY])->assertSuccessful();

    expect(saRows())->toEqual([
        ...collect(saExpected())->except(['invoiced_kobo|sms_credits', 'invoiced_net_kobo|sms_credits', 'collections_kobo|proof', 'collections|proof'])->all(),
        'invoiced_kobo|' => 1_300_750, 'invoiced_net_kobo|' => 1_210_000,
        'collections_kobo|' => 870_000, 'collections|' => 5,
        'outstanding_kobo|' => 4_515_000, 'outstanding_invoices|' => 1,
        ...saExpectedPerOrganization($o),
    ]);
});

it('makes up missed nights: flows for the days missed, stocks only for the day just ended', function () {
    analyticsWorld();
    $runner = app(SnapshotRunner::class);

    // A first snapshot ever on 20 February, then the scheduler stopped until now.
    DB::table('daily_platform_metrics')->insert(['date' => '2027-02-20', 'metric' => 'snapshot', 'dimension' => 'stocks', 'value' => 1]);
    DB::table('daily_platform_metrics')->insert(['date' => '2027-02-20', 'metric' => 'snapshot', 'dimension' => 'flows', 'value' => 1]);

    expect($runner->nightly())->toBe(['2027-02-22', '2027-02-23', '2027-02-24', '2027-02-25', '2027-02-26', '2027-02-27', '2027-02-28', SA_DAY]);

    $markers = DB::table('daily_platform_metrics')->where('metric', 'snapshot')->where('date', '>', '2027-02-20')->get()
        ->groupBy(fn (object $row): string => (string) $row->date)->map(fn ($rows) => $rows->pluck('dimension')->sort()->values()->all())->all();
    expect($markers['2027-02-26'])->toBe(['flows'])
        ->and($markers[SA_DAY])->toBe(['flows', 'stocks'])
        ->and(saRows('2027-02-26'))->not->toHaveKey('mrr_kobo|')
        // 26 Feb: the one sign-in that day; 25 Feb: K's first invoice issued.
        ->and(saRows('2027-02-26')['active_users_1d|'])->toBe(1)
        ->and(saRows('2027-02-25')['invoiced_kobo|'])->toBe(430_000)
        ->and($runner->nightly())->toBe([]);
});

it('takes only the day just ended on a new installation, and never two runs at once', function () {
    analyticsWorld();
    $lock = Cache::lock('platform-metrics-snapshot', 600);
    $lock->get();

    expect(app(SnapshotRunner::class)->nightly())->toBe([])
        ->and(DB::table('daily_platform_metrics')->count())->toBe(0);

    $lock->release();

    expect(app(SnapshotRunner::class)->nightly())->toBe([SA_DAY])
        ->and(DB::table('daily_platform_metrics')->distinct()->pluck('date')->map(fn ($d) => substr((string) $d, 0, 10))->all())->toBe([SA_DAY]);
});

it('runs every hour at :10 inside the cron process, and by hand with a check on the date', function () {
    $event = collect(app(Schedule::class)->events())->first(fn ($event) => $event->description === 'metrics-snapshot');
    expect($event)->not->toBeNull()->and($event->expression)->toBe('10 * * * *');

    analyticsWorld();

    $this->artisan('metrics:snapshot')->expectsOutputToContain('Taken: '.SA_DAY)->assertSuccessful();
    $this->artisan('metrics:snapshot')->expectsOutputToContain('Nothing to do')->assertSuccessful();
    // An older day by hand: its flows only (its stocks as they stood are gone).
    $this->artisan('metrics:snapshot', ['date' => '2027-02-25'])->assertSuccessful();
    expect(saRows('2027-02-25'))->toHaveKey('invoiced_kobo|')->not->toHaveKey('mrr_kobo|');

    $this->artisan('metrics:snapshot', ['date' => '2027-03-02'])->expectsOutputToContain('has not ended yet')->assertFailed();
    $this->artisan('metrics:snapshot', ['date' => '2027-02-30'])->expectsOutputToContain('is not a date')->assertFailed();
    $this->artisan('metrics:snapshot', ['date' => 'yesterday'])->assertFailed();
});

it('acceptance: the System analytics page shows the fixture\'s figures', function () {
    $o = analyticsWorld();
    app(SnapshotRunner::class)->nightly();
    $this->actingAs(staffNamed('Amaka', ['analyst']), 'staff');

    $html = saPage();
    $figures = saFigures($html);

    expect($figures)->toMatchArray([
        'mrr' => saMoney(5_543_333),
        'mrr_change' => '+'.saMoney(0),
        'arr' => saMoney(66_519_996),
        'paying_customers' => '3',
        'revenue_this_month' => saMoney(1_510_000),
        'revenue_last_month' => saMoney(0),
        'collection_rate' => '371.5%',
        'received' => saMoney(6_030_750),
        'invoiced' => saMoney(1_623_250),
        'days_to_pay' => '7.6',
        'invoices_paid' => '3',
        'outstanding' => saMoney(4_837_500),
        'outstanding_invoices' => '2',
        'overdue' => saMoney(4_515_000),
        'signups' => '2', 'signups_agency' => '1', 'signups_property_owner' => '1', 'signups_hybrid' => '0',
        'trial_conversion' => '100.0%', 'trial_conversions' => '1', 'trials_ended' => '1',
        'churn_rate' => '33.3%', 'cancellations' => '1', 'paying_at_start' => '3', 'trial_cancellations' => '1',
        'status_active' => '2', 'status_past_due' => '1', 'status_grace' => '0', 'status_trialing' => '1',
        'status_suspended' => '0', 'status_cancelled' => '2', 'status_none' => '1', 'customers' => '7', 'covered' => '2',
        'units' => '90', 'units_added' => '1', 'active_day' => '2', 'active_week' => '3', 'portal_tenants' => '2',
        'tickets_opened' => '2', 'tickets_resolved' => '1',
        'tier_1' => '6', 'tier_2' => '1', 'tier_3' => '0',
        'applications_pending' => '2', 'applications_approved' => '1', 'applications_rejected' => '1', 'flags_open' => '2',
        'collections' => saMoney(970_000), 'collections_count' => '6',
        'collections_2027-03_bank_match' => saMoney(500_000), 'collections_2027-03_paystack' => saMoney(250_000),
        'collections_2027-03_proof' => saMoney(100_000), 'collections_2027-03_cash' => saMoney(50_000),
        'collections_2027-03_transfer' => saMoney(70_000), 'collections_2027-03' => saMoney(970_000),
    ]);

    // Leaderboards: by units B, A, K, C; reliability A (2 of 3) then B (1 of 3); J has too few invoices.
    preg_match('/data-board="units".*?<\/table>/s', $html, $units);
    preg_match('/data-board="reliability".*?<\/table>/s', $html, $reliability);
    preg_match_all('/data-leader="(\d+)"/', $units[0], $byUnits);
    preg_match_all('/data-leader="(\d+)"/', $reliability[0], $byReliability);
    expect(array_map('intval', $byUnits[1]))->toBe([$o['B'], $o['A'], $o['K'], $o['C']])
        ->and(array_map('intval', $byReliability[1]))->toBe([$o['A'], $o['B']])
        ->and($reliability[0])->toContain('66.6%')->toContain('33.3%')
        // Covered landlords are never on it; the analyst gets names, no links into Customers.
        ->and($html)->toContain('Bello Homes')->not->toContain('Ese Okafor')->not->toContain('/customers/');

    // MRR by plan.
    expect($html)->toContain('data-plan="starter"')->toContain('data-plan="growth"');
});

it('reads only the nightly summary, never the live records', function () {
    $o = analyticsWorld();
    app(SnapshotRunner::class)->nightly();
    $this->actingAs(staffNamed('Amaka', ['analyst']), 'staff');

    saUnits($o['B'], saBuilding($o['B']), 10, SA_NOW);
    saOrganization('Late Signup Ltd', [], SA_NOW);
    DB::table('platform_invoices')->update(['status' => 'void']);

    expect(saFigures(saPage()))->toMatchArray(['units' => '90', 'signups' => '2', 'mrr' => saMoney(5_543_333), 'outstanding' => saMoney(4_837_500)]);
});

it('compares stocks with the day before the range and adds flows over the days taken only', function () {
    analyticsWorld();
    app(SnapshotRunner::class)->nightly();
    // A snapshot of 27 Feb with fewer paying customers and a lower MRR.
    app(MetricsSnapshot::class)->take(CarbonImmutable::parse('2027-02-27', 'Africa/Lagos'), withStocks: true);
    DB::table('daily_platform_metrics')->where('date', '2027-02-27')->where('metric', 'paying_customers')->where('dimension', '')->update(['value' => 4]);
    DB::table('daily_platform_metrics')->where('date', '2027-02-27')->where('metric', 'mrr_kobo')->where('dimension', '')->update(['value' => 5_000_000]);
    $this->actingAs(staffNamed('Amaka', ['analyst']), 'staff');

    $html = saPage(['range' => 'custom', 'from' => '2027-02-28', 'to' => SA_DAY]);
    $figures = saFigures($html);

    expect($figures)->toMatchArray([
        'paying_at_start' => '4',
        'churn_rate' => '25.0%',
        'mrr_change' => '+'.saMoney(543_333),
        'signups' => '2',
    ])->and($html)->toContain('data-missing-days>1<');

    // A range before any snapshot: an empty state, no figures.
    $empty = saPage(['range' => 'custom', 'from' => '2026-01-01', 'to' => '2026-01-31']);
    expect($empty)->toContain('No figures for this period yet')->not->toContain('data-figure="mrr"');
});

it('opens for analysts, operations managers and super-admins only; only staff who see Customers get links', function () {
    analyticsWorld();
    app(SnapshotRunner::class)->nightly();
    $console = '/'.config('app.admin_path');

    foreach (['analyst', 'operations-manager', 'super-admin'] as $role) {
        $this->flushSession();
        $this->actingAs(staffNamed(Str::studly($role), [$role]), 'staff');
        $this->get($console.'/analytics?range=custom&from='.SA_DAY.'&to='.SA_DAY)->assertOk()->assertSee('System analytics')->assertSee('Leaderboard');
        $this->get($console.'/analytics/export/revenue')->assertOk();
    }

    $this->flushSession();
    $this->actingAs(staffNamed('Ops', ['operations-manager']), 'staff');
    expect(saPage())->toContain('/customers/');

    foreach (['technical-admin', 'support-agent', 'finance-admin', 'compliance-officer', 'content-manager'] as $role) {
        $this->flushSession();
        $this->actingAs(staffNamed(Str::studly($role).'X', [$role]), 'staff');
        $this->get($console.'/analytics')->assertForbidden();
        $this->get($console.'/analytics/export/revenue')->assertForbidden();
        Livewire::test(SystemAnalytics::class)->assertForbidden();
    }

    $this->flushSession();
    auth('staff')->logout();
    $this->get($console.'/analytics')->assertRedirect();
    $this->get($console.'/analytics/export/revenue')->assertRedirect();
    $this->actingAs(staffNamed('Amaka', ['analyst']), 'staff');
    $this->get($console.'/analytics/export/nonsense')->assertNotFound();
});

it('downloads each section as CSV for the page\'s range, audited', function () {
    $o = analyticsWorld();
    DB::table('organizations')->where('id', $o['C'])->update(['name' => '=HYPERLINK("http://evil.example")']);
    app(SnapshotRunner::class)->nightly();
    $analyst = staffNamed('Amaka', ['analyst']);
    $this->actingAs($analyst, 'staff');
    $console = '/'.config('app.admin_path');
    $query = '?range=custom&from=2027-02-28&to='.SA_DAY;

    $revenue = $this->get($console.'/analytics/export/revenue'.$query)->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
    $lines = explode("\r\n", trim(ltrim($revenue->streamedContent(), "\u{FEFF}")));
    expect($revenue->headers->get('Content-Disposition'))->toContain('goproperty-analytics-revenue-2027-02-28-to-2027-03-01.csv')
        ->and($lines[0])->toStartWith('Date,MRR,"Paying customers"')
        ->and($lines[1])->toBe('2027-02-28,,,,,,,,,,,')
        ->and($lines[2])->toBe('2027-03-01,55433.33,3,16232.50,15100.00,60307.50,500.00,3,23,48375.00,2,45150.00');

    $collections = $this->get($console.'/analytics/export/collections'.$query)->streamedContent();
    expect($collections)->toContain('2027-03-01,9700.00,6,5000.00,2500.00,1000.00,500.00,700.00');

    $leaderboard = $this->get($console.'/analytics/export/leaderboard'.$query)->streamedContent();
    expect($leaderboard)->toContain('"By units",1,"Bello Homes",60,0,3,1,33.3%')
        ->toContain("'=HYPERLINK")
        ->not->toContain('Ese Okafor');

    $states = $this->get($console.'/analytics/export/states'.$query)->streamedContent();
    expect($states)->toContain('Lagos,3,2027-03-01')->toContain('Kano,1,2027-03-01');

    foreach (['customers', 'usage', 'tiers'] as $section) {
        expect($this->get($console.'/analytics/export/'.$section.$query)->assertOk()->streamedContent())->toContain('2027-03-01');
    }

    $entry = DB::table('activity_log')->where('log_name', 'analytics')->where('event', 'analytics.exported')->orderBy('id')->first();
    expect($entry)->not->toBeNull()
        ->and((int) $entry->causer_id)->toBe($analyst->id)
        ->and(json_decode((string) $entry->properties, true))->toMatchArray(['section' => 'revenue', 'from' => '2027-02-28', 'to' => SA_DAY, 'rows' => 2])
        ->and(DB::table('activity_log')->where('log_name', 'analytics')->count())->toBe(7);
});

it('cleans the date range: presets end yesterday, typed dates are swapped, cut and capped', function () {
    $now = CarbonImmutable::parse(SA_NOW, 'UTC');
    $range = fn (?string $preset, ?string $from = null, ?string $to = null): array => [
        AnalyticsRange::from($preset, $from, $to, $now)->preset,
        AnalyticsRange::from($preset, $from, $to, $now)->from->toDateString(),
        AnalyticsRange::from($preset, $from, $to, $now)->to->toDateString(),
    ];

    expect($range(null))->toBe(['30d', '2027-01-31', SA_DAY])
        ->and($range('7d'))->toBe(['7d', '2027-02-23', SA_DAY])
        ->and($range('90d'))->toBe(['90d', '2026-12-02', SA_DAY])
        ->and($range('12m'))->toBe(['12m', '2026-03-02', SA_DAY])
        ->and($range('year'))->toBe(['year', '2027-01-01', SA_DAY])
        ->and($range('custom', '2027-02-10', '2027-01-05'))->toBe(['custom', '2027-01-05', '2027-02-10'])
        ->and($range('custom', '2027-02-10', '2027-12-31'))->toBe(['custom', '2027-02-10', SA_DAY])
        ->and($range('custom', '2019-05-01', '2020-03-01'))->toBe(['custom', '2020-01-01', '2020-03-01'])
        ->and($range('custom', '2020-01-01', '2026-12-31'))->toBe(['custom', '2024-12-31', '2026-12-31'])
        ->and($range('custom', '2027-02-30', SA_DAY))->toBe(['30d', '2027-01-31', SA_DAY])
        ->and($range('custom', 'yesterday', null))->toBe(['30d', '2027-01-31', SA_DAY])
        ->and($range('forever'))->toBe(['30d', '2027-01-31', SA_DAY]);

    // On the page the cleaned range goes back into the URL.
    $this->actingAs(staffNamed('Amaka', ['analyst']), 'staff');
    Livewire::withQueryParams(['range' => 'custom', 'from' => '2027-02-10', 'to' => '2027-01-05'])->test(SystemAnalytics::class)
        ->assertSet('from', '2027-01-05')->assertSet('to', '2027-02-10')
        ->set('preset', '7d')->assertSet('from', '2027-02-23')->assertSet('to', SA_DAY)
        ->set('from', '2027-02-01')->assertSet('preset', 'custom')->assertSet('from', '2027-02-01');
});
