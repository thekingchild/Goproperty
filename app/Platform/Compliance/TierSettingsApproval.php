<?php

declare(strict_types=1);

namespace App\Platform\Compliance;

use App\Models\ApprovalRequest;
use App\Models\PlatformStaff;
use App\Platform\Approvals\ApprovalHandler;
use App\Platform\Approvals\HandlesNotApproved;

/**
 * A change to the Transaction Tier settings under the two-person rule
 * (P06-08; ONBOARDING_COMPLIANCE.md § 5.2, PLATFORM_CONSOLE.md § 1A):
 * approving the request (ApprovalAction::TierSettings,
 * `tiers.approve_settings`, never the proposer) approves the version, which
 * applies from its date; rejecting it or letting it expire means it never
 * applies.
 */
final readonly class TierSettingsApproval implements ApprovalHandler, HandlesNotApproved
{
    public function __construct(private TierSettings $settings) {}

    public function refusalFor(PlatformStaff $decider, ApprovalRequest $request): ?string
    {
        return null;
    }

    public function apply(ApprovalRequest $request, PlatformStaff $approver): void
    {
        $this->settings->approve($request, $approver);
    }

    public function notApproved(ApprovalRequest $request): void
    {
        $this->settings->notApproved($request);
    }
}
