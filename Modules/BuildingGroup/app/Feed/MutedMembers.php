<?php

declare(strict_types=1);

namespace Modules\BuildingGroup\Feed;

use Carbon\CarbonImmutable;
use Modules\BuildingGroup\Models\BuildingGroup;
use Modules\BuildingGroup\Models\GroupMember;
use Modules\Tenants\Contracts\TenantDirectory;

/**
 * The group's current members who are muted now, the soonest unmuted
 * first, with the agency's record of their name (P07-04; moderators only).
 */
final readonly class MutedMembers
{
    public function __construct(private TenantDirectory $tenants) {}

    /** @return list<MutedMember> */
    public function of(BuildingGroup $group): array
    {
        $rows = GroupMember::query()->where('building_group_id', $group->id)->current()
            ->where('muted_until', '>', CarbonImmutable::now())
            ->orderBy('muted_until')->orderBy('id')
            ->get();

        $names = array_map(static fn ($tenant): string => $tenant->name, $this->tenants->findMany(array_values(array_filter($rows->pluck('tenant_profile_id')->all(), static fn (mixed $id): bool => $id !== null))));

        return array_values($rows->map(static fn (GroupMember $row): MutedMember => new MutedMember(
            memberId: $row->id,
            name: $row->tenant_profile_id !== null && isset($names[$row->tenant_profile_id]) ? $names[$row->tenant_profile_id] : __('A tenant'),
            until: $row->muted_until ?? CarbonImmutable::now(),
        ))->all());
    }
}
