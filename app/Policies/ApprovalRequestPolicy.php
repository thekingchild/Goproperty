<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\ApprovalRequest;
use App\Models\PlatformStaff;
use App\Platform\Approvals\ApprovalAction;
use App\Platform\Approvals\ApprovalService;
use App\Platform\Staff\StaffPermission as P;

/**
 * Console → Staff → Approvals (PLATFORM_CONSOLE.md § 1). A request is
 * decided only by someone other than its requester who holds the action's
 * permission (ApprovalService::refusalFor, the one rule for the queue and
 * the service).
 */
final class ApprovalRequestPolicy
{
    public function __construct(private readonly ApprovalService $approvals) {}

    /** The queue: anyone who may decide some action, or see every request. */
    public function viewAny(PlatformStaff $actor): bool
    {
        if ($actor->holdsStaffPermission(P::ApprovalsView)) {
            return true;
        }

        foreach (ApprovalAction::cases() as $action) {
            if ($actor->holdsStaffPermission($action->approverPermission())) {
                return true;
            }
        }

        return false;
    }

    public function view(PlatformStaff $actor, ApprovalRequest $request): bool
    {
        return $actor->holdsStaffPermission(P::ApprovalsView)
            || $actor->getKey() === $request->requested_by_staff_id
            || $actor->holdsStaffPermission($request->action->approverPermission());
    }

    public function approve(PlatformStaff $actor, ApprovalRequest $request): bool
    {
        return $this->approvals->canDecide($actor, $request);
    }

    public function reject(PlatformStaff $actor, ApprovalRequest $request): bool
    {
        return $this->approvals->canDecide($actor, $request);
    }

    /** Requests are opened by the feature that needs them, never by hand. */
    public function create(PlatformStaff $actor): bool
    {
        return false;
    }

    public function update(PlatformStaff $actor, ApprovalRequest $request): bool
    {
        return false;
    }

    public function delete(PlatformStaff $actor, ApprovalRequest $request): bool
    {
        return false;
    }

    public function deleteAny(PlatformStaff $actor): bool
    {
        return false;
    }
}
