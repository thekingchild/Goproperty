<?php

declare(strict_types=1);

namespace Modules\Properties\OwnerPortal;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * The "records linked to my user" read of the owner portal
 * (ARCHITECTURE.md § 4, P03-15): an owner's buildings may be managed by
 * any number of organizations, so the portal never sets one organization
 * context and asks which organizations hold an owner profile linked to the
 * user (`owner_profiles.user_id`, archived profiles left out). One
 * unscoped read of two columns, `organization_id` and `id`, as P01-04 reads
 * an invitation's organization by its token (orchestrator decision 2);
 * everything else is loaded inside that organization's context with
 * OrganizationContext::run, the scope in place (OwnerPortfolios). Reads
 * only.
 */
final class LinkedOwnerProfiles
{
    /**
     * The user's (current) owner profiles, by organization.
     *
     * @return array<int, list<int>> organization id => owner profile ids
     */
    public function byOrganization(User $user): array
    {
        $profiles = [];

        foreach (DB::table('owner_profiles')
            ->where('user_id', $user->id)
            ->whereNull('deleted_at')
            ->orderBy('organization_id')
            ->orderBy('id')
            ->get(['organization_id', 'id']) as $row) {
            $profiles[(int) $row->organization_id][] = (int) $row->id;
        }

        return $profiles;
    }

    /** Whether the user is linked to an owner profile anywhere (where they land, App\Tenancy\PortalLanding). */
    public function any(User $user): bool
    {
        return DB::table('owner_profiles')->where('user_id', $user->id)->whereNull('deleted_at')->exists();
    }
}
