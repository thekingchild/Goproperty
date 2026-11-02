<?php

declare(strict_types=1);

use App\Models\Organization;
use App\Models\User;
use App\Tenancy\OrganizationContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Modules\BuildingGroup\Groups\BuildingGroups;
use Modules\BuildingGroup\Jobs\SyncBuildingGroups;
use Modules\BuildingGroup\Models\GroupMember;
use Modules\Leasing\Jobs\RenderLeaseContract;
use Modules\Properties\Buildings\Properties;
use Modules\Properties\Contracts\PropertyDirectory;
use Modules\Properties\Models\Property;
use Modules\Tenants\Models\TenantProfile;

require_once __DIR__.'/helpers.php';
require_once __DIR__.'/../../../Leasing/tests/Feature/Import/helpers.php';

/*
 * P07-01: imported leases join and leave like any other (an import is not
 * an activation, but an ongoing lease is someone living there), and the
 * nightly `building-groups-sync` backfills the leases that existed before
 * the Building Group, corrects anything an event missed, closes the group
 * of a building that is no longer active, and changes nothing on a second
 * run (AGENT.md § 3 rule 6).
 */

beforeEach(function () {
    Storage::fake('media');
    holdInvoicePdfs();
    holdReceiptPdfs();
    seedOrganizationRoles();
    $this->travelTo('2026-10-28 10:00:00');
    $this->acme = Organization::factory()->create(['slug' => 'acme-homes', 'name' => 'Adeyemi Realty Ltd']);
    $this->owner = joinAs(User::factory()->create(['name' => 'Ada Owner', 'email' => 'ada.owner@example.com', 'phone' => '+2348020000001']), $this->acme);
});

afterEach(fn () => leasesFreshRequest());

/** The organization's tenant record with this phone. */
function tenantWithPhone(Organization $organization, string $phone): TenantProfile
{
    return inOrganization($organization, fn (): TenantProfile => TenantProfile::query()->where('phone', $phone)->firstOrFail());
}

it('acceptance: an import\'s ongoing leases add their tenants, history adds nobody, and its rollback removes them', function () {
    $batch = portfolioImport($this->acme, $this->owner, ongoingRentsWorkbook());
    $palm = inOrganization($this->acme, fn (): Property => Property::query()->where('name', 'Palm Court')->firstOrFail());
    $adaeze = tenantWithPhone($this->acme, '+2348035550101');
    $bayo = tenantWithPhone($this->acme, '+2348035550102');

    // The imported building has its group (PropertyCreated); L-1 and L-2 are ongoing, L-3 (Chika) is history.
    expect(groupOf($this->acme, $palm)?->is_active)->toBeTrue()
        ->and(membersOf($this->acme, $palm))->toBe([$adaeze->id, $bayo->id]);

    // The same file again changes nobody.
    $before = groupTables();
    portfolioImport($this->acme, $this->owner, ongoingRentsWorkbook());
    expect(groupTables())->toEqual($before);

    portfolioRollback($this->acme, $this->owner, $batch);
    expect(membersOf($this->acme, $palm))->toBe([]);

    // The building itself was removed with the import: tonight's sync closes its group.
    $this->travelTo('2026-10-29 02:20:00');
    runGroupSync();
    $group = groupOf($this->acme, $palm);
    expect($group->is_active)->toBeFalse()
        ->and($group->closed_at?->toDateTimeString())->toBe('2026-10-29 02:20:00');
});

it('acceptance: the nightly sync opens the missing groups, puts the members right, closes archived buildings\' groups, and changes nothing the second time', function () {
    Queue::fake([RenderLeaseContract::class]);
    $palm = buildingIn($this->acme, ['name' => 'Palm Court', 'slug' => 'palm-court']);
    $flat1 = unitIn($this->acme, $palm, ['unit_label' => 'Flat 1']);
    $flat2 = unitIn($this->acme, $palm, ['unit_label' => 'Flat 2']);
    $lagoon = buildingIn($this->acme, ['name' => 'Lagoon View', 'slug' => 'lagoon-view']);
    $empty = buildingIn($this->acme, ['name' => 'Cedar House', 'slug' => 'cedar-house']);
    $kemi = tenantIn($this->acme, ['first_name' => 'Kemi', 'last_name' => 'Adebayo', 'phone' => '+2348031112222']);
    $tunde = tenantIn($this->acme, ['first_name' => 'Tunde', 'last_name' => 'Bakare', 'phone' => '+2348034445555']);
    $musa = tenantIn($this->acme, ['first_name' => 'Musa', 'last_name' => 'Danjuma', 'phone' => '+2348036667777']);
    activeLease($this->acme, $this->owner, $flat1, $kemi);
    activeLease($this->acme, $this->owner, $flat2, $tunde);
    activeLease($this->acme, $this->owner, unitIn($this->acme, $lagoon, ['unit_label' => 'Suite 1']), $musa);

    // As before P07-01: leases, but no groups (rows removed behind the writer's back).
    DB::table('group_members')->delete();
    DB::table('building_groups')->delete();
    // And a stale member nobody removed: Musa in Palm Court without a lease there.
    $this->travelTo('2026-10-28 11:00:00');
    inOrganization($this->acme, fn () => app(BuildingGroups::class)->sync($palm->id));
    $palmGroup = groupOf($this->acme, $palm);
    inOrganization($this->acme, fn () => tap(new GroupMember, fn (GroupMember $member) => $member->forceFill([
        'building_group_id' => $palmGroup->id, 'member_type' => 'tenant', 'tenant_profile_id' => $musa->id, 'joined_at' => now(),
    ])->save()));
    // Cedar House archived (no lease holds it), with a group from before.
    inOrganization($this->acme, fn () => app(BuildingGroups::class)->sync($empty->id));
    inOrganization($this->acme, fn () => app(Properties::class)->archive($this->owner, $empty));

    $this->travelTo('2026-10-29 02:20:00');
    runGroupSync();

    expect(membersOf($this->acme, $palm))->toBe([$kemi->id, $tunde->id])
        ->and(memberRow($this->acme, $palm, $musa)->left_at?->toDateTimeString())->toBe('2026-10-29 02:20:00')
        ->and(groupOf($this->acme, $lagoon)?->is_active)->toBeTrue()
        ->and(membersOf($this->acme, $lagoon))->toBe([$musa->id])
        ->and(memberRow($this->acme, $lagoon, $musa)->joined_at?->toDateTimeString())->toBe('2026-10-29 02:20:00')
        ->and(groupOf($this->acme, $empty)?->is_active)->toBeFalse()
        ->and(groupOf($this->acme, $empty)?->closed_at?->toDateTimeString())->toBe('2026-10-29 02:20:00');

    // Idempotent: the next night changes nothing.
    $before = groupTables();
    $this->travelTo('2026-10-30 02:20:00');
    runGroupSync();
    expect(groupTables())->toEqual($before);

    // Restored, Cedar House's group opens again.
    inOrganization($this->acme, fn () => app(Properties::class)->restore($this->owner, inOrganization($this->acme, fn () => Property::query()->findOrFail($empty->id))));
    $this->travelTo('2026-10-31 02:20:00');
    runGroupSync();
    expect(groupOf($this->acme, $empty)?->is_active)->toBeTrue()
        ->and(groupOf($this->acme, $empty)?->closed_at)->toBeNull();
});

it('stops when its time is up and queues itself again from the building it stopped at', function () {
    $palm = buildingIn($this->acme, ['name' => 'Palm Court', 'slug' => 'palm-court']);
    $lagoon = buildingIn($this->acme, ['name' => 'Lagoon View', 'slug' => 'lagoon-view']);
    Queue::fake([SyncBuildingGroups::class]);

    // No time at all: nothing synced, the rest queued from the first building.
    (new SyncBuildingGroups(budgetSeconds: -1))->handle(app(OrganizationContext::class), app(BuildingGroups::class), app(PropertyDirectory::class));

    expect(groupOf($this->acme, $palm))->toBeNull();
    Queue::assertPushed(SyncBuildingGroups::class, fn (SyncBuildingGroups $job): bool => [$job->fromOrganizationId, $job->fromPropertyId] === [$this->acme->id, $palm->id] && $job->queue === 'default');

    // The queued run picks up from there and finishes.
    (new SyncBuildingGroups($this->acme->id, $palm->id))->handle(app(OrganizationContext::class), app(BuildingGroups::class), app(PropertyDirectory::class));
    expect(groupOf($this->acme, $palm)?->is_active)->toBeTrue()
        ->and(groupOf($this->acme, $lagoon)?->is_active)->toBeTrue();
    Queue::assertPushed(SyncBuildingGroups::class, 1);
});
