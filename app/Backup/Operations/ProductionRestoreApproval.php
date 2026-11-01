<?php

declare(strict_types=1);

namespace App\Backup\Operations;

use App\Models\ApprovalRequest;
use App\Models\PlatformStaff;
use App\Platform\Approvals\ApprovalHandler;
use App\Platform\Approvals\HandlesNotApproved;

/**
 * A restore to production under the two-person rule (P06-10;
 * PLATFORM_CONSOLE.md § 1, § 4): approving the request
 * (ApprovalAction::ProductionRestore, `backups.approve_production_restore`,
 * never the requester) queues the restore for the scheduler's next minute
 * tick; rejecting it or letting it expire (one day) cancels it.
 */
final readonly class ProductionRestoreApproval implements ApprovalHandler, HandlesNotApproved
{
    public function __construct(private BackupOperations $operations) {}

    public function refusalFor(PlatformStaff $decider, ApprovalRequest $request): ?string
    {
        return $this->operations->refusalForApprover($decider, $request);
    }

    public function apply(ApprovalRequest $request, PlatformStaff $approver): void
    {
        $this->operations->approveProduction($request, $approver);
    }

    public function notApproved(ApprovalRequest $request): void
    {
        $this->operations->productionNotApproved($request);
    }
}
