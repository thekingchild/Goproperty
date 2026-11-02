<?php

declare(strict_types=1);

namespace Modules\BuildingGroup\Enums;

use App\Enums\Concerns\HasValues;

/**
 * Who wrote a post or comment (`group_posts.author_kind`,
 * `group_comments.author_kind`; P07-02), and so which side of the group
 * they wrote from: a tenant of the building from the portal, or a member
 * of the agency (staff) from the workspace. The same user may be both
 * (a member who rents in a building of their own organization); each page
 * writes as its own side.
 */
enum AuthorKind: string
{
    use HasValues;

    case Tenant = 'tenant';
    case Staff = 'staff';
}
