<?php

declare(strict_types=1);

namespace App\Platform\Approvals;

use App\Models\ApprovalRequest;
use App\Models\PlatformStaff;

/**
 * What runs when a request for one action is approved. Registered with
 * ApprovalService::handle() by the feature that owns the action (role
 * grants here; refunds, restores, impersonation and purges in later tasks).
 * An action without a handler is only marked approved, for features that
 * check the approval themselves (e.g. write-mode impersonation, P01-08).
 */
interface ApprovalHandler
{
    /**
     * Extra rules for who may decide (on top of "not the requester" and the
     * action's permission). Return the reason in words, or null when allowed.
     */
    public function refusalFor(PlatformStaff $decider, ApprovalRequest $request): ?string;

    /**
     * Carries out the approved action, inside the approval's transaction.
     * Throw ApprovalRefused if it can no longer run.
     */
    public function apply(ApprovalRequest $request, PlatformStaff $approver): void;
}
