<?php

declare(strict_types=1);

namespace Modules\BuildingGroup\Groups;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use LogicException;
use Modules\BuildingGroup\Enums\MemberRole;
use Modules\BuildingGroup\Enums\MemberType;
use Modules\BuildingGroup\Models\BuildingGroup;
use Modules\BuildingGroup\Models\GroupMember;
use Modules\Leasing\Contracts\BuildingResidents;
use Modules\Properties\Contracts\PropertyDirectory;

/**
 * The only writer of `building_groups` and `group_members` (P07-01;
 * ARCHITECTURE.md § 6.8, docs/plans/P07-subtasks.md).
 *
 * sync() brings one building's group in step with the organization's
 * records, in the current organization's context:
 *
 * - The group is opened when the building is one of the organization's
 *   active buildings and has none yet (one per building and organization),
 *   and is active exactly while the building is: archived, removed by an
 *   import rollback, or handed to another organization, it is closed
 *   (`closed_at`); restored or handed back, it opens again.
 * - Its tenant members are exactly the tenants holding an active lease in
 *   the building (Leasing's BuildingResidents): a new lease adds the
 *   tenant, the end of their last active lease there (expired, terminated,
 *   removed by an import rollback) removes them. A renewal keeps them (the
 *   renewal is active), so does a second unit in the building.
 *
 * It recomputes the whole building rather than applying one lease's change,
 * so any order of events, a replayed event or the nightly sync all end in
 * the same members. The group's row is locked first, so two changes to the
 * same building wait for each other. Inside a caller's transaction (a lease
 * change, a building move) it commits or rolls back with it.
 */
final readonly class BuildingGroups
{
    public function __construct(
        private PropertyDirectory $properties,
        private BuildingResidents $residents,
    ) {}

    public function sync(int $propertyId): MembershipChange
    {
        return DB::transaction(function () use ($propertyId): MembershipChange {
            $building = $this->properties->find($propertyId);
            $active = $building !== null && ! $building->archived;
            $group = $this->locked($propertyId);

            if ($group === null && ! $active) {
                return new MembershipChange(null, false);
            }

            $group ??= $this->open($propertyId);
            $now = CarbonImmutable::now();

            if ($group->is_active !== $active) {
                $group->is_active = $active;
                $group->closed_at = $active ? null : $now;
                $group->save();
            }

            [$joined, $left] = $this->syncTenants($group, $this->residents->tenantsIn([$propertyId])[$propertyId] ?? [], $now);

            return new MembershipChange($group->id, $active, $joined, $left);
        }, 3);
    }

    /** The building's group here, locked until the transaction ends. */
    private function locked(int $propertyId): ?BuildingGroup
    {
        return BuildingGroup::query()->where('property_id', $propertyId)->lockForUpdate()->first();
    }

    /** A new group (or the one a concurrent sync has just made), locked. */
    private function open(int $propertyId): BuildingGroup
    {
        // Insert first (in a savepoint): a concurrent opening waits on the
        // unique key instead of both taking gap locks and deadlocking.
        BuildingGroup::query()->createOrFirst(['property_id' => $propertyId], ['is_active' => true]);

        return $this->locked($propertyId) ?? throw new LogicException('The Building Group was just opened.');
    }

    /**
     * @param  list<int>  $residents
     * @return array{0: list<int>, 1: list<int>} joined, left
     */
    private function syncTenants(BuildingGroup $group, array $residents, CarbonImmutable $now): array
    {
        $rows = GroupMember::query()
            ->where('building_group_id', $group->id)
            ->where('member_type', MemberType::Tenant)
            ->get()
            ->keyBy('tenant_profile_id');

        $joined = [];
        $left = [];

        foreach ($residents as $tenantId) {
            $row = $rows->get($tenantId);

            if ($row instanceof GroupMember && $row->left_at === null) {
                continue;
            }

            if ($row instanceof GroupMember) {
                $row->left_at = null;
                $row->joined_at = $now;
                $row->save();
            } else {
                $member = new GroupMember;
                $member->building_group_id = $group->id;
                $member->member_type = MemberType::Tenant;
                $member->tenant_profile_id = $tenantId;
                $member->role = MemberRole::Member;
                $member->joined_at = $now;
                $member->save();
            }

            $joined[] = $tenantId;
        }

        foreach ($rows as $tenantId => $row) {
            if ($row->left_at === null && ! in_array((int) $tenantId, $residents, true)) {
                $row->left_at = $now;
                $row->save();
                $left[] = (int) $tenantId;
            }
        }

        sort($left);

        return [$joined, $left];
    }
}
