<?php

declare(strict_types=1);

namespace Modules\BuildingGroup\Enums;

use App\Enums\Concerns\HasValues;

/**
 * Who a group member is (`group_members.member_type`, DATABASE_SCHEMA.md
 * § 6). Since P07-01 only tenants are stored, kept in step with leases;
 * staff and the building's owner reach the group through their permissions
 * (docs/plans/P07-subtasks.md default 3). The other values are the
 * schema's, kept for the tasks that store them.
 */
enum MemberType: string
{
    use HasValues;

    case Tenant = 'tenant';
    case Agent = 'agent';
    case Owner = 'owner';
    case PlatformViewer = 'platform_viewer';
}
