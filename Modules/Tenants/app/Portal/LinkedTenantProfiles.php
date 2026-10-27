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

    /**
     * The user's (current) tenant profiles, by organization: the second
     * read of the portal path (P03-11a, Portal\PortalTenancies), still two
     * columns, `organization_id` and `id`. Each profile is loaded and
     * policy-checked inside its organization afterwards.
     *
     * @return array<int, list<int>> organization id => tenant profile ids
     */
    public function byOrganization(User $user): array
    {
        $profiles = [];

        foreach (DB::table('tenant_profiles')
            ->where('user_id', $user->id)
            ->whereNull('deleted_at')
            ->orderBy('organization_id')
            ->orderBy('id')
            ->get(['organization_id', 'id']) as $row) {
            $profiles[(int) $row->organization_id][] = (int) $row->id;
        }

        return $profiles;
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
