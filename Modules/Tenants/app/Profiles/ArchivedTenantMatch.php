<?php

declare(strict_types=1);

namespace Modules\Tenants\Profiles;

use Illuminate\Validation\ValidationException;
use Modules\Tenants\Models\TenantProfile;

/**
 * Adding a tenant whose phone number belongs to an archived tenant
 * (P02 decision 3: the phone is unique per organization, archived tenants
 * included). The phone field gets the message; the form offers to restore
 * that tenant instead ($tenantId) rather than adding the person twice.
 */
final class ArchivedTenantMatch extends ValidationException
{
    public int $tenantId = 0;

    public static function of(TenantProfile $tenant): self
    {
        $refused = self::withMessages(['phone' => __(':name has this phone number and was archived. Restore them instead of adding them again.', ['name' => $tenant->fullName()])]);
        $refused->tenantId = $tenant->id;

        return $refused;
    }
}
