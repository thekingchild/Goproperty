<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Permission\Models\Role as SpatieRole;

/**
 * A spatie role (ARCHITECTURE.md § 5). `organization_id` is null for the
 * roles every organization shares and for staff roles, and set only for a
 * role one organization defined for itself. Staff role columns
 * (`is_default`, `is_protected`, `description`, `active`) come with P01-07.
 *
 * @property int $id
 * @property int|null $organization_id
 */
class Role extends SpatieRole
{
    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
