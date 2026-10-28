<?php

declare(strict_types=1);

namespace Modules\Properties\OwnerPortal;

use App\Enums\OrganizationPermission;
use App\Enums\OrganizationRole;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Modules\Properties\Models\OwnerProfile;

/**
 * The owner ↔ user link of the owner portal (P03-15), in one place: an
 * owner profile linked to a user (`owner_profiles.user_id`) who holds
 * `property-owner` in the profile's organization (ARCHITECTURE.md § 5,
 * BILLING_PAYMENTS.md § 7.4). Both are needed for the portal to show the
 * owner's buildings (OwnerProfilePolicy::viewAsOwner). Used by the
 * invitation's acceptance and the agency's "Remove access"; the management
 * handover (P03-14) links the landlord's user to the receiving
 * organization's owner profile with link(). Inside the profile's
 * organization, in the caller's transaction, on a locked profile. No
 * policy check (the callers check theirs) and no audit (theirs).
 */
final readonly class OwnerLinks
{
    /** Links the profile to the user, who joins the organization with `property-owner` (only that, never a workspace role). */
    public function link(OwnerProfile $owner, User $user, Organization $organization): void
    {
        $owner->forceFill(['user_id' => $user->id])->save();
        $user->joinOrganization($organization, OrganizationRole::PropertyOwner);
    }

    /**
     * Unlinks the profile; the user loses `property-owner` here (unless
     * another of their owner profiles here is still linked) and the
     * membership when nothing else is held here. Whether the membership
     * went. The account, and anything it does elsewhere, stays.
     */
    public function unlink(OwnerProfile $owner, ?User $user, Organization $organization): bool
    {
        $owner->forceFill(['user_id' => null])->save();

        if ($user === null || OwnerProfile::query()->where('user_id', $user->id)->exists()) {
            return false;
        }

        if ($user->hasRole(OrganizationRole::PropertyOwner->value, OrganizationPermission::GUARD)) {
            $user->removeRole(OrganizationRole::PropertyOwner->value);
        }

        $user->unsetRelation('roles')->unsetRelation('permissions');

        $holdsMore = DB::table('model_has_roles')->where('organization_id', $organization->id)->where('model_type', $user->getMorphClass())->where('model_id', $user->id)->exists()
            || DB::table('model_has_permissions')->where('organization_id', $organization->id)->where('model_type', $user->getMorphClass())->where('model_id', $user->id)->exists();

        return ! $holdsMore && Membership::query()->where('user_id', $user->id)->delete() > 0;
    }
}
