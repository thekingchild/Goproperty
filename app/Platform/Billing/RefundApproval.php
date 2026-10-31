<?php

declare(strict_types=1);

namespace App\Platform\Billing;

use App\Models\ApprovalRequest;
use App\Models\PlatformStaff;
use App\Platform\Approvals\ApprovalHandler;
use App\Platform\Approvals\ApprovalRefused;
use App\Platform\Approvals\HandlesNotApproved;
use Modules\SaasBilling\Contracts\PlatformRefunds;
use Modules\SaasBilling\Contracts\RefundRefused;

/**
 * Refunds above the configured amount under the two-person rule
 * (PLATFORM_CONSOLE.md § 1; P06-07b): approving the request
 * (ApprovalAction::Refund, `refunds.approve`, never the requester) runs the
 * refund through SaasBilling inside the approval's transaction, so a refusal
 * (Paystack down, the payment refunded meanwhile) leaves the request
 * pending; rejecting it or letting it expire frees the amount.
 */
final class RefundApproval implements ApprovalHandler, HandlesNotApproved
{
    public function refusalFor(PlatformStaff $decider, ApprovalRequest $request): ?string
    {
        return null;
    }

    public function apply(ApprovalRequest $request, PlatformStaff $approver): void
    {
        try {
            $this->refunds()->approve($approver, $this->refundId($request), $request->id);
        } catch (RefundRefused $refused) {
            throw new ApprovalRefused($refused->getMessage(), previous: $refused);
        }
    }

    public function notApproved(ApprovalRequest $request): void
    {
        $this->refunds()->notApproved($this->refundId($request));
    }

    private function refundId(ApprovalRequest $request): int
    {
        $id = $request->payload['refund_id'] ?? null;

        return is_numeric($id) ? (int) $id : throw new ApprovalRefused('This request names no refund.');
    }

    private function refunds(): PlatformRefunds
    {
        if (! app()->bound(PlatformRefunds::class)) {
            throw new ApprovalRefused('Refunds cannot be made: the SaasBilling module is not enabled.');
        }

        return app(PlatformRefunds::class);
    }
}
