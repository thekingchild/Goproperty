<?php

declare(strict_types=1);

namespace App\Platform\Impersonation;

use App\Models\ApprovalRequest;
use App\Models\Organization;
use App\Models\PlatformStaff;
use App\Models\User;
use App\Platform\Approvals\ApprovalHandler;
use App\Platform\Approvals\ApprovalRefused;

/**
 * Write-mode impersonation under the two-person rule (PLATFORM_CONSOLE.md
 * § 1, § 6): a second staff member holding `impersonate.write` approves the
 * request (ApprovalAction::WriteImpersonation). Approving starts nothing:
 * the requester then starts the session once, within an hour of the
 * approval (Impersonations::start() checks the approved request). On
 * approval this only checks the session could still happen.
 */
final class WriteImpersonationApproval implements ApprovalHandler
{
    public function refusalFor(PlatformStaff $decider, ApprovalRequest $request): ?string
    {
        return null;
    }

    public function apply(ApprovalRequest $request, PlatformStaff $approver): void
    {
        $user = User::query()->find((int) ($request->payload['user_id'] ?? 0));
        $organization = Organization::query()->find((int) ($request->payload['organization_id'] ?? 0));

        if ($user === null || $organization === null) {
            throw new ApprovalRefused('The user or organization no longer exists.');
        }

        if (! $user->belongsToOrganization($organization)) {
            throw new ApprovalRefused("{$user->name} is no longer a member of {$organization->name}.");
        }

        if ($user->email !== null && PlatformStaff::query()->whereRaw('LOWER(email) = ?', [mb_strtolower($user->email)])->exists()) {
            throw new ApprovalRefused('Staff accounts cannot be impersonated.');
        }
    }
}
