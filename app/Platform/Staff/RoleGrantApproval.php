<?php

declare(strict_types=1);

namespace App\Platform\Staff;

use App\Models\ApprovalRequest;
use App\Models\PlatformStaff;
use App\Platform\Approvals\ApprovalHandler;

/**
 * Approval of a `role_grant` request: a second super-admin
 * (PLATFORM_CONSOLE.md § 1A) approves, then the grant is applied.
 */
final readonly class RoleGrantApproval implements ApprovalHandler
{
    public function __construct(private StaffRoles $roles) {}

    public function refusalFor(PlatformStaff $decider, ApprovalRequest $request): ?string
    {
        return $decider->isSuperAdmin() ? null : 'Role grants are approved by a second super-admin.';
    }

    public function apply(ApprovalRequest $request, PlatformStaff $approver): void
    {
        $this->roles->applyApproved($request, $approver);
    }
}
