<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Modules\Billing\Models\Bill;
use Tests\Support\QueryCounter;

require_once __DIR__.'/report-world.php';

/*
 * P09-03 (load test): the Reports page worked its figures out from every
 * bill and payment of the range as models (a year of a large agency:
 * ~12,000 of each, 12 s cold). It now reads sums and plain rows, so a cold
 * load runs the same queries for the report world as with a year of
 * monthly bills and payments more, and the figures still add up.
 */

beforeEach(function () {
    Notification::fake();
    statementWorld();
});

afterEach(fn () => exportsFreshRequest());

it('works a year of figures out with the same queries for a few bills as for many more', function () {
    $year = ['from' => '2026-02-01', 'to' => '2027-01-31'];
    $cold = function () use ($year): array {
        Cache::flush();
        exportsFreshRequest();
        $report = null;
        $queries = QueryCounter::count(function () use (&$report, $year): void {
            $report = reportOf($this->acme, $this->owner, $year);
        });

        return [$queries, $report];
    };

    [$few, $before] = $cold();

    // A tenant with a monthly lease since February 2026: 12 bills, each paid.
    $cedar = buildingIn($this->acme, ['name' => 'Cedar Court', 'slug' => 'cedar-court']);
    $tenant = tenantIn($this->acme, ['first_name' => 'Yemi', 'last_name' => 'Lawal', 'phone' => '+2348039990001', 'email' => 'yemi.lawal@example.com']);
    $lease = activeLease($this->acme, $this->owner, unitIn($this->acme, $cedar, ['unit_label' => 'Shop 1']), $tenant, [
        'billingCycle' => 'monthly', 'startDate' => '2026-02-01', 'endDate' => '2027-12-31', 'rent' => '100,000', 'deposit' => '0',
    ]);
    runBillGeneration();
    $bills = inOrganization($this->acme, fn () => Bill::query()->where('lease_id', $lease->id)->orderBy('id')->get());

    foreach ($bills as $bill) {
        confirmedPayment($this->acme, $this->owner, $tenant, '100,000', [], [$bill->id => '100,000']);
    }

    [$many, $after] = $cold();

    expect($bills->count())->toBeGreaterThanOrEqual(12)
        ->and($many)->toBe($few)
        // Billed and collected both grew by the new lease's bills, all issued and paid in the year.
        ->and($after->collections->totals->billedTotal()->kobo - $before->collections->totals->billedTotal()->kobo)->toBe($bills->count() * 10_000_000)
        ->and($after->collections->totals->collectedTotal()->kobo - $before->collections->totals->collectedTotal()->kobo)->toBe($bills->count() * 10_000_000);
});

it('counts a bill issued just after Lagos midnight on the 1st in the new month, whatever the app\'s timezone', function () {
    // 00:30 on 1 February in Lagos is 23:30 on 31 January in UTC (the test suite's timezone).
    $this->travelTo(CarbonImmutable::parse('2027-02-01 00:30:00', 'Africa/Lagos'));
    runBillGeneration();
    $range = ['from' => '2027-01-01', 'to' => '2027-02-28'];
    $months = function () use ($range): array {
        Cache::flush();
        exportsFreshRequest();

        return array_map(fn ($month) => [$month->month->key, $month->totals->billedTotal()->kobo], reportOf($this->acme, $this->owner, $range)->collections->months);
    };
    $before = $months();

    // Billed on its billing date: 7 days before the period starts (billing.lead_days), so now.
    $cedar = buildingIn($this->acme, ['name' => 'Cedar Court', 'slug' => 'cedar-court']);
    $tenant = tenantIn($this->acme, ['first_name' => 'Yemi', 'last_name' => 'Lawal', 'phone' => '+2348039990001', 'email' => 'yemi.lawal@example.com']);
    activeLease($this->acme, $this->owner, unitIn($this->acme, $cedar, ['unit_label' => 'Shop 1']), $tenant, [
        'billingCycle' => 'monthly', 'startDate' => '2027-02-08', 'endDate' => '2027-12-31', 'rent' => '100,000', 'deposit' => '0',
    ]);
    runBillGeneration();

    expect($months())->toBe([[$before[0][0], $before[0][1]], [$before[1][0], $before[1][1] + 10_000_000]])
        ->and(array_column($before, 0))->toBe(['2027-01', '2027-02']);
});
