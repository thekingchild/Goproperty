<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\TierApplicationStatus;
use App\Models\PlatformStaff;
use App\Platform\Compliance\ReviewApplication;
use App\Platform\Staff\StaffPermission as P;

/**
 * Console → Compliance → Tier applications (PLATFORM_CONSOLE.md § 1): the
 * queue and an application's page need `tiers.review`; asking for more
 * information too; approving and rejecting need `tiers.approve`
 * (`compliance-officer`, `super-admin`). Opening the documents themselves
 * is ReviewDocumentPolicy::viewFile. Applications are never created,
 * edited or deleted here. TierReview re-checks.
 */
final class TierReviewPolicy
{
    public function viewAny(PlatformStaff $actor): bool
    {
        return $actor->holdsStaffPermission(P::TiersReview);
    }

    public function view(PlatformStaff $actor, ReviewApplication $application): bool
    {
        return $this->viewAny($actor);
    }

    public function startReview(PlatformStaff $actor, ReviewApplication $application): bool
    {
        return $this->viewAny($actor) && $application->status === TierApplicationStatus::Submitted;
    }

    public function requestMoreInfo(PlatformStaff $actor, ReviewApplication $application): bool
    {
        return $this->viewAny($actor) && $application->status->isWithCompliance();
    }

    public function approve(PlatformStaff $actor, ReviewApplication $application): bool
    {
        return $this->viewAny($actor) && $actor->holdsStaffPermission(P::TiersApprove) && $application->status->isWithCompliance();
    }

    public function reject(PlatformStaff $actor, ReviewApplication $application): bool
    {
        return $this->approve($actor, $application);
    }

    public function create(PlatformStaff $actor): bool
    {
        return false;
    }

    public function update(PlatformStaff $actor, ReviewApplication $application): bool
    {
        return false;
    }

    public function delete(PlatformStaff $actor, ReviewApplication $application): bool
    {
        return false;
    }

    public function deleteAny(PlatformStaff $actor): bool
    {
        return false;
    }
}
