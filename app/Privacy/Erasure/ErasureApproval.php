<?php

declare(strict_types=1);

namespace App\Privacy\Erasure;

use App\Models\ApprovalRequest;
use App\Models\DataRequest;
use App\Models\PlatformStaff;
use App\Platform\Approvals\ApprovalHandler;
use App\Platform\Approvals\ApprovalRefused;
use App\Platform\Approvals\ApprovalStatus;
use App\Platform\Approvals\HandlesNotApproved;
use App\Privacy\DataRequestScope;
use App\Privacy\DataRequestStatus;
use App\Privacy\PrivacyAudit;
use Carbon\CarbonImmutable;

/**
 * The two-person rule for erasures (PLATFORM_CONSOLE.md § 1: "Deleting/
 * purging an organization's data (NDPA erasure)", approver permission
 * `data.purge`; P09-02). Approving does not erase: the request waits until
 * the retention period has passed (`due_at`), and the scheduler then
 * carries it out (RetentionEnforcer). The approver is refused while the
 * organization or tenant does not qualify (RetentionRules). Rejected or
 * expired, the request is closed.
 */
final readonly class ErasureApproval implements ApprovalHandler, HandlesNotApproved
{
    public function __construct(
        private RetentionRules $retention,
        private PrivacyAudit $audit,
    ) {}

    public function refusalFor(PlatformStaff $decider, ApprovalRequest $request): ?string
    {
        $dataRequest = $this->dataRequest($request);

        if ($dataRequest === null || $dataRequest->status !== DataRequestStatus::AwaitingApproval) {
            return 'This erasure is no longer waiting for approval.';
        }

        return null;
    }

    public function apply(ApprovalRequest $request, PlatformStaff $approver): void
    {
        $dataRequest = $this->dataRequest($request, lock: true) ?? throw new ApprovalRefused('This erasure is no longer waiting for approval.');
        $tenant = $dataRequest->scope === DataRequestScope::Tenant;
        $refusal = $tenant
            ? $this->retention->tenantRefusal($dataRequest->organization_id, (int) $dataRequest->tenant_profile_id)
            : $this->retention->organizationRefusal($dataRequest->organization_id);

        if ($refusal !== null) {
            throw new ApprovalRefused($refusal);
        }

        $due = $tenant
            ? $this->retention->tenantDueAt($dataRequest->organization_id, (int) $dataRequest->tenant_profile_id)
            : $this->retention->organizationDueAt($dataRequest->organization_id);
        $due = $due->greaterThan(CarbonImmutable::now()) ? $due : CarbonImmutable::now();

        $dataRequest->forceFill(['status' => DataRequestStatus::Waiting, 'due_at' => $due])->save();
        $this->audit->record('data_erasure.approved', $dataRequest, $approver, 'Erasure approved: '.$dataRequest->summary(), [
            'approval_request_id' => $request->id,
            'due_at' => $due->toIso8601String(),
        ]);
    }

    public function notApproved(ApprovalRequest $request): void
    {
        $dataRequest = $this->dataRequest($request, lock: true);

        if ($dataRequest === null) {
            return;
        }

        $expired = $request->status === ApprovalStatus::Expired;
        $dataRequest->forceFill([
            'status' => $expired ? DataRequestStatus::Cancelled : DataRequestStatus::Rejected,
            'failure' => $expired ? 'Nobody approved it in time.' : 'Not approved.',
            'completed_at' => now(),
        ])->save();
        $this->audit->record($expired ? 'data_erasure.expired' : 'data_erasure.rejected', $dataRequest, null, 'Erasure not approved: '.$dataRequest->summary());
    }

    private function dataRequest(ApprovalRequest $request, bool $lock = false): ?DataRequest
    {
        $id = $request->payload['data_request_id'] ?? null;

        if (! is_int($id)) {
            return null;
        }

        $query = DataRequest::query()->whereKey($id)->where('status', DataRequestStatus::AwaitingApproval);

        return ($lock ? $query->lockForUpdate() : $query)->first();
    }
}
