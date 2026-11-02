<?php

declare(strict_types=1);

namespace Modules\BuildingGroup\Enums;

use App\Enums\Concerns\HasValues;

/** A member's role in the group (`group_members.role`, DATABASE_SCHEMA.md § 6); tenants are members only (ARCHITECTURE.md § 6.8). */
enum MemberRole: string
{
    use HasValues;

    case Member = 'member';
    case Moderator = 'moderator';
    case Admin = 'admin';
}
