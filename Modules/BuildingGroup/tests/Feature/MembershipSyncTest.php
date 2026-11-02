<?php

declare(strict_types=1);

use App\Models\Organization;
use App\Models\User;
use App\Tenancy\OrganizationContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Modules\BuildingGroup\Enums\MemberRole;
use Modules\BuildingGroup\Enums\MemberType;
use Modules\BuildingGroup\Models\BuildingGroup;
use Modules\Leasing\Enums\LeaseStatus;
use Modules\Leasing\Jobs\ExpireAndFlagLeases;
use Modules\Leasing\Jobs\RenderLeaseContract;
use Modules\Leasing\Leases\LeaseService;
use Modules\Properties\Buildings\Properties;
use Modules\Properties\Models\Property;

require_once __DIR__.'/helpers.php';
require_once __DIR__.'/../../../Properties/tests/Feature/Buildings/helpers.php';

/*
 * P07-01 AC (IMPLEMENTATION_PLAN.md § 3): every building has its group,
 * opened automatically; a new lease adds the tenant to the group and
 * ending a lease removes them (ARCHITECTURE.md § 6.8; docs/plans/
 * P07-subtasks.md). Leases go through LeaseService, the nightly sync runs
 * as the scheduler runs it.
 *
 * Adeyemi Realty Ltd (`acme-homes`): Ada Owner; Palm Court (Flat 1,
 * Flat 2) and Lagoon View (Suite 1); tenants Kemi Adebayo and Tunde Bakare.
 * Eze Properties (`eze-properties`) has its own Palm Court and Kemi Adebayo
 * (same phone).
 */

beforeEach(function () {
    seedOrganizationRoles();
    Queue::fake([RenderLeaseContract::class]);
    $this->travelTo('2026-12-20 10:00:00');
    $this->acme = Organization::factory()->create(['slug' => 'acme-homes', 'name' => 'Adeyemi Realty Ltd']);
    $this->ada = joinAs(User::factory()->create(['name' => 'Ada Owner', 'email' => 'ada.owner@example.com', 'phone' => '+2348020000001']), $this->acme);
    $this->palm = buildingIn($this->acme, ['name' => 'Palm Court', 'slug' => 'palm-court']);
    $this->flat1 = unitIn($this->acme, $this->palm, ['unit_label' => 'Flat 1']);
    $this->flat2 = unitIn($this->acme, $this->palm, ['unit_label' => 'Flat 2']);
    $this->lagoon = buildingIn($this->acme, ['name' => 'Lagoon View', 'slug' => 'lagoon-view']);
    $this->suite1 = unitIn($this->acme, $this->lagoon, ['unit_label' => 'Suite 1']);
    $this->kemi = tenantIn($this->acme, ['first_name' => 'Kemi', 'last_name' => 'Adebayo', 'phone' => '+2348031112222', 'email' => 'kemi.adebayo@example.com']);
    $this->tunde = tenantIn($this->acme, ['first_name' => 'Tunde', 'last_name' => 'Bakare', 'phone' => '+2348034445555', 'email' => 'tunde.bakare@example.com']);
});

afterEach(fn () => leasesFreshRequest());

function leases(): LeaseService
{
    return app(LeaseService::class);
}

it('acceptance: a new lease adds the tenant to the building\'s group', function () {
    // A draft (the unit reserved, nobody moved in) adds nobody.
    $draft = draftLease($this->acme, $this->ada, $this->flat1, $this->kemi);
    expect(membersOf($this->acme, $this->palm))->toBe([]);

    $this->travelTo('2026-12-21 09:00:00');
    inOrganization($this->acme, fn () => withoutContractPdfs(fn () => leases()->activate($this->ada, $draft)));

    $group = groupOf($this->acme, $this->palm);
    $row = memberRow($this->acme, $this->palm, $this->kemi);

    expect($group)->not->toBeNull()
        ->and($group->is_active)->toBeTrue()
        ->and($group->closed_at)->toBeNull()
        ->and($group->organization_id)->toBe($this->acme->id)
        ->and(membersOf($this->acme, $this->palm))->toBe([$this->kemi->id])
        ->and([$row->member_type, $row->role, $row->user_id, $row->left_at])->toBe([MemberType::Tenant, MemberRole::Member, null, null])
        ->and($row->joined_at?->toDateTimeString())->toBe('2026-12-21 09:00:00')
        // Only her building's group.
        ->and(membersOf($this->acme, $this->lagoon))->toBe([]);

    // A second tenant joins the same group.
    activeLease($this->acme, $this->ada, $this->flat2, $this->tunde);
    expect(membersOf($this->acme, $this->palm))->toBe([$this->kemi->id, $this->tunde->id])
        ->and(inOrganization($this->acme, fn () => BuildingGroup::query()->count()))->toBe(1);
});

it('acceptance: ending a lease removes the tenant from the group', function (string $how) {
    $lease = activeLease($this->acme, $this->ada, $this->flat1, $this->kemi);
    activeLease($this->acme, $this->ada, $this->flat2, $this->tunde);
    expect(membersOf($this->acme, $this->palm))->toBe([$this->kemi->id, $this->tunde->id]);

    if ($how === 'terminated') {
        $this->travelTo('2027-03-10 12:00:00');
        inOrganization($this->acme, fn () => leases()->terminate($this->ada, $lease, 'Moved abroad for work'));
        $endedAt = '2027-03-10 12:00:00';
    } else {
        // The daily leases:expire-and-flag after the end date (31 December 2027).
        $this->travelTo('2028-01-01 02:00:00');
        app(OrganizationContext::class)->clear();
        ExpireAndFlagLeases::dispatchSync();
        $endedAt = '2028-01-01 02:00:00';
    }

    expect(storedLease($this->acme, $lease)->status->value)->toBe($how);

    $row = memberRow($this->acme, $this->palm, $this->kemi);
    expect(membersOf($this->acme, $this->palm))->toBe($how === 'terminated' ? [$this->tunde->id] : [])
        // The row stays, with when she left.
        ->and($row)->not->toBeNull()
        ->and($row->left_at?->toDateTimeString())->toBe($endedAt)
        ->and(groupOf($this->acme, $this->palm)->is_active)->toBeTrue();
})->with(['terminated', 'expired']);

it('keeps the tenant through a renewal, and removes them when the renewal ends', function () {
    $lease = activeLease($this->acme, $this->ada, $this->flat1, $this->kemi);
    $joined = memberRow($this->acme, $this->palm, $this->kemi)->joined_at;

    $this->travelTo('2027-11-01 10:00:00');
    $renewal = inOrganization($this->acme, fn () => leases()->renew($this->ada, $lease, leaseInput(['startDate' => '2028-01-01', 'endDate' => '2028-12-31'])));
    inOrganization($this->acme, fn () => withoutContractPdfs(fn () => leases()->activate($this->ada, $renewal)));

    expect(storedLease($this->acme, $lease)->status)->toBe(LeaseStatus::Renewed)
        ->and(membersOf($this->acme, $this->palm))->toBe([$this->kemi->id])
        ->and(memberRow($this->acme, $this->palm, $this->kemi)->joined_at)->toEqual($joined);

    // The renewed lease reaching its end date changes nothing (the renewal is active).
    $this->travelTo('2028-01-02 02:00:00');
    app(OrganizationContext::class)->clear();
    ExpireAndFlagLeases::dispatchSync();
    expect(membersOf($this->acme, $this->palm))->toBe([$this->kemi->id]);

    inOrganization($this->acme, fn () => leases()->terminate($this->ada, storedLease($this->acme, $renewal), 'Bought a house'));
    expect(membersOf($this->acme, $this->palm))->toBe([]);
});

it('keeps a tenant with two units in the building until their last lease there ends', function () {
    $first = activeLease($this->acme, $this->ada, $this->flat1, $this->kemi);
    $second = activeLease($this->acme, $this->ada, $this->flat2, $this->kemi, ['rent' => '900,000']);
    expect(memberRow($this->acme, $this->palm, $this->kemi)->joined_at?->toDateTimeString())->toBe('2026-12-20 10:00:00');

    $this->travelTo('2027-02-01 10:00:00');
    inOrganization($this->acme, fn () => leases()->terminate($this->ada, $first, 'Gave up the second flat'));
    expect(membersOf($this->acme, $this->palm))->toBe([$this->kemi->id]);

    inOrganization($this->acme, fn () => leases()->terminate($this->ada, $second, 'Left Lagos'));
    expect(membersOf($this->acme, $this->palm))->toBe([]);
});

it('moves a tenant who moves buildings, and brings back a tenant who comes back on the same row', function () {
    $palmLease = activeLease($this->acme, $this->ada, $this->flat1, $this->kemi);
    $rowId = memberRow($this->acme, $this->palm, $this->kemi)->id;

    $this->travelTo('2027-02-01 10:00:00');
    inOrganization($this->acme, fn () => leases()->terminate($this->ada, $palmLease, 'Moving to Lagoon View'));
    $lagoonLease = activeLease($this->acme, $this->ada, $this->suite1, $this->kemi, ['startDate' => '2027-02-01', 'endDate' => '2028-01-31']);

    expect(membersOf($this->acme, $this->palm))->toBe([])
        ->and(membersOf($this->acme, $this->lagoon))->toBe([$this->kemi->id]);

    // Back to Palm Court a few months later: the same row, a new join date.
    $this->travelTo('2027-06-01 10:00:00');
    inOrganization($this->acme, fn () => leases()->terminate($this->ada, $lagoonLease, 'Moving back'));
    activeLease($this->acme, $this->ada, $this->flat2, $this->kemi, ['startDate' => '2027-06-01', 'endDate' => '2028-05-31']);

    $row = memberRow($this->acme, $this->palm, $this->kemi);
    expect(membersOf($this->acme, $this->palm))->toBe([$this->kemi->id])
        ->and(membersOf($this->acme, $this->lagoon))->toBe([])
        ->and($row->id)->toBe($rowId)
        ->and($row->left_at)->toBeNull()
        ->and($row->joined_at?->toDateTimeString())->toBe('2027-06-01 10:00:00');
});

it('changes the members with the lease or not at all', function () {
    $draft = draftLease($this->acme, $this->ada, $this->flat1, $this->kemi);

    // The activation's transaction rolls back after the membership was written.
    expect(fn () => DB::transaction(function () use ($draft): void {
        inOrganization($this->acme, fn () => withoutContractPdfs(fn () => leases()->activate($this->ada, $draft)));
        expect(membersOf($this->acme, $this->palm))->toBe([$this->kemi->id]);

        throw new RuntimeException('Something failed later in the same request');
    }))->toThrow(RuntimeException::class);

    expect(storedLease($this->acme, $draft)->status)->toBe(LeaseStatus::Draft)
        ->and(groupOf($this->acme, $this->palm))->toBeNull()
        ->and(membersOf($this->acme, $this->palm))->toBe([]);

    // A cancelled draft changes nobody.
    inOrganization($this->acme, fn () => leases()->terminate($this->ada, $draft, 'Changed their mind'));
    expect(groupOf($this->acme, $this->palm))->toBeNull();
});

it('keeps each organization\'s groups apart', function () {
    $eze = Organization::factory()->create(['slug' => 'eze-properties', 'name' => 'Eze Properties']);
    $ngozi = joinAs(User::factory()->create(['name' => 'Ngozi Eze', 'email' => 'ngozi.eze@example.com', 'phone' => '+2348051112222']), $eze);
    $ezePalm = buildingIn($eze, ['name' => 'Palm Court', 'slug' => 'palm-court']);
    $ezeFlat = unitIn($eze, $ezePalm, ['unit_label' => 'Flat 1']);
    $ezeKemi = tenantIn($eze, ['first_name' => 'Kemi', 'last_name' => 'Adebayo', 'phone' => '+2348031112222']);

    activeLease($this->acme, $this->ada, $this->flat1, $this->kemi);
    $ezeLease = activeLease($eze, $ngozi, $ezeFlat, $ezeKemi);

    expect(membersOf($this->acme, $this->palm))->toBe([$this->kemi->id])
        ->and(membersOf($eze, $ezePalm))->toBe([$ezeKemi->id])
        ->and(groupOf($this->acme, $ezePalm))->toBeNull()
        ->and(groupOf($eze, $this->palm))->toBeNull();

    inOrganization($eze, fn () => leases()->terminate($ngozi, $ezeLease, 'Moved out'));
    expect(membersOf($eze, $ezePalm))->toBe([])
        ->and(membersOf($this->acme, $this->palm))->toBe([$this->kemi->id]);
});

it('acceptance: opens a group for every building added, with nobody in it yet', function () {
    $ocean = inOrganization($this->acme, fn (): Property => app(Properties::class)->create($this->ada, buildingForm(['name' => 'Ocean Heights'])));

    $group = groupOf($this->acme, $ocean);
    expect($group)->not->toBeNull()
        ->and($group->is_active)->toBeTrue()
        ->and($group->name)->toBeNull()
        ->and(membersOf($this->acme, $ocean))->toBe([]);
});
