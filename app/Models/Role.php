<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Permission\Models\Role as SpatieRole;

/**
 * A spatie role (ARCHITECTURE.md § 5). `organization_id` is null for the
 * roles every organization shares and for staff roles, and set only for a
 * role one organization defined for itself. The staff role columns
 * (`is_default`, `is_protected`, `description`, `active`) are used on the
 * `staff` guard only (StaffRole); customer roles keep their defaults.
 *
 * @property int $id
 * @property int|null $organization_id
 * @property bool $is_default
 * @property bool $is_protected
 * @property string|null $description
 * @property bool $active
 */
class Role extends SpatieRole
{
    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'is_protected' => 'boolean',
            'active' => 'boolean',
        ];
    }
}
