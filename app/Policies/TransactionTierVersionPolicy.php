<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\PlatformStaff;
use App\Models\TransactionTierVersion;
use App\Platform\Staff\StaffPermission as P;

/**
 * Console → Compliance → Tier settings (P06-08; PLATFORM_CONSOLE.md § 1A):
 * seen by those who propose (`tiers.configure`), approve
 * (`tiers.approve_settings`) or override (`tiers.override`) tier limits;
 * proposals and cancelling a scheduled version need `tiers.configure`
 * (re-checked by App\Platform\Compliance\TierSettings). Approving is in
 * Staff → Approvals (ApprovalRequestPolicy). Versions are never edited or
 * deleted.
 */
final class TransactionTierVersionPolicy
{
    public function viewAny(PlatformStaff $actor): bool
    {
        return $actor->holdsStaffPermission(P::TiersConfigure)
            || $actor->holdsStaffPermission(P::TiersApproveSettings)
            || $actor->holdsStaffPermission(P::TiersOverride);
    }

    public function view(PlatformStaff $actor, TransactionTierVersion $version): bool
    {
        return $this->viewAny($actor);
    }

    /** Proposing the next version of a tier. */
    public function propose(PlatformStaff $actor): bool
    {
        return $actor->holdsStaffPermission(P::TiersConfigure);
    }

    public function cancel(PlatformStaff $actor, TransactionTierVersion $version): bool
    {
        return $actor->holdsStaffPermission(P::TiersConfigure) && $version->isScheduled();
    }

    public function create(PlatformStaff $actor): bool
    {
        return false;
    }

    public function update(PlatformStaff $actor, TransactionTierVersion $version): bool
    {
        return false;
    }

    public function delete(PlatformStaff $actor, TransactionTierVersion $version): bool
    {
        return false;
    }

    public function deleteAny(PlatformStaff $actor): bool
    {
        return false;
    }
}
