<?php

declare(strict_types=1);

namespace Modules\Tenants\Portal;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * The "records linked to my user" path of the tenant portal
 * (ARCHITECTURE.md § 4): a tenant rents from any number of organizations,
 * so the portal never sets one organization context and asks which
 * organizations hold a tenant profile linked to the user
 * (`tenant_profiles.user_id`, archived profiles left out). One unscoped
 * read of two columns, as P01-04 reads an invitation's organization by its
 * token; anything more is loaded inside that organization's context with
 * OrganizationContext::run, the scope in place.
 */
final class LinkedTenantProfiles
{
    /** @return list<int> organization ids where $user is a (current) tenant */
    public function organizationIds(User $user): array
    {
        return array_values(array_map('intval', DB::table('tenant_profiles')
            ->where('user_id', $user->id)
            ->whereNull('deleted_at')
            ->distinct()
            ->orderBy('organization_id')
            ->pluck('organization_id')
            ->all()));
    }

    public function any(User $user): bool
    {
        return DB::table('tenant_profiles')->where('user_id', $user->id)->whereNull('deleted_at')->exists();
    }

    /** Is $user also a tenant of an organization other than $organizationId? */
    public function elsewhere(User $user, int $organizationId): bool
    {
        return DB::table('tenant_profiles')
            ->where('user_id', $user->id)
            ->where('organization_id', '!=', $organizationId)
            ->whereNull('deleted_at')
            ->exists();
    }
}
