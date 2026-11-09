<?php

declare(strict_types=1);

use App\Enums\OrganizationRole;
use App\Models\Organization;
use App\Models\User;
use Tests\Support\QueryCounter;

require_once __DIR__.'/helpers.php';

/*
 * P09-03 (load test): the bills list is a hot path, so it runs the same
 * queries for a page of 2 bills as for a full page of 25 from many
 * tenants, units and buildings (Lists\BillParties reads each kind of name
 * once), for the whole organization and for an agent narrowed to
 * buildings, on a page load and on a Livewire update.
 */

beforeEach(function () {
    holdInvoicePdfs();
    seedOrganizationRoles();
    $this->travelTo('2026-12-25 09:00:00');
    $this->acme = Organization::factory()->create(['slug' => 'acme-homes']);
    $this->owner = joinAs(User::factory()->create(), $this->acme);
    $this->agent = joinAs(User::factory()->create(), $this->acme, OrganizationRole::Agent);
});

afterEach(fn () => billsFreshRequest());

/** Leases from 1 Jan 2027 in $buildings new buildings, $perBuilding units each, billed once. */
function billedLeases(Organization $organization, User $owner, User $agent, int $buildings, int $perBuilding, int $from): void
{
    foreach (range(1, $buildings) as $b) {
        $building = buildingIn($organization, ['name' => "Block {$from}-{$b}"]);
        assignIn($organization, $agent, $building);

        foreach (range(1, $perBuilding) as $u) {
            $n = $from * 1000 + $b * 100 + $u;
            $tenant = tenantIn($organization, ['first_name' => "T{$n}", 'last_name' => 'Okafor', 'phone' => sprintf('+234803%07d', $n), 'email' => "t{$n}@example.test"]);
            activeLease($organization, $owner, unitIn($organization, $building, ['unit_label' => "Unit {$u}"]), $tenant, ['startDate' => '2027-01-01', 'endDate' => '2027-12-31']);
        }
    }

    runBillGeneration();
}

it('lists 2 bills and 25 bills with the same queries, for the organization and a narrowed agent', function () {
    $count = fn (User $user, string $path = '') => QueryCounter::count(fn () => billsPage($user, $path)->assertOk());
    $update = function (User $user): int {
        $snapshot = billsSnapshot($user);

        return QueryCounter::count(fn () => billsUpdate($user, $snapshot, ['status' => 'unpaid'])->assertOk());
    };

    billedLeases($this->acme, $this->owner, $this->agent, 1, 2, 1);
    // A first visit records things once (the workspace remembered, …): not the rows' doing.
    billsPage($this->owner)->assertOk();
    billsPage($this->agent)->assertOk();
    $few = [$count($this->owner), $count($this->agent), $count($this->owner, '?q=Okafor'), $update($this->owner)];

    billedLeases($this->acme, $this->owner, $this->agent, 5, 6, 2);
    expect(billsOf($this->acme))->toHaveCount(32);
    $many = [$count($this->owner), $count($this->agent), $count($this->owner, '?q=Okafor'), $update($this->owner)];

    expect($many)->toBe($few);
});
