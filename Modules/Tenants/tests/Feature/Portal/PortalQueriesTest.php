<?php

declare(strict_types=1);

use Tests\Support\QueryCounter;

require_once __DIR__.'/portal-world.php';

/*
 * P09-03 (load test): the portal's home and My bills are hot paths (every
 * tenant opens them), so they run the same queries for a tenant with one
 * bill as for one with a second, monthly lease and a year of bills at the
 * same agency (Portal\PortalRentals reads each kind of record once per
 * agency, not once per bill).
 */

beforeEach(fn () => portalWorld());

afterEach(fn () => billsFreshRequest());

it('shows one bill and a year of bills with the same queries', function () {
    $count = fn (string $path) => QueryCounter::count(fn () => portalPage($this->kemi, $path)->assertOk());

    // A first visit records things once: not the rows' doing.
    portalPage($this->kemi)->assertOk();
    $few = [$count(''), $count('/bills')];

    $palm = buildingIn($this->acme, ['name' => 'Cedar House', 'slug' => 'cedar-house']);
    activeLease($this->acme, $this->owner, unitIn($this->acme, $palm, ['unit_label' => 'Shop 1']), $this->kemiAtAcme, [
        'billingCycle' => 'monthly', 'startDate' => '2026-01-01', 'endDate' => '2027-12-31', 'rent' => '150,000',
    ]);
    runBillGeneration();
    expect(portalIds(portalPage($this->kemi, '/bills'), 'bill'))->toHaveCount(14);

    expect([$count(''), $count('/bills')])->toBe($few);
});
