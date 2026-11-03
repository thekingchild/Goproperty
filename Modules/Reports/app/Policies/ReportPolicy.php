<?php

declare(strict_types=1);

namespace Modules\Reports\Policies;

use App\Enums\OrganizationPermission;
use App\Models\User;
use App\Tenancy\OrganizationContext;
use Modules\Reports\Reports\PortfolioReport;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;

/**
 * The Reports page (P08-01; ARCHITECTURE.md § 5 "Reports"): the customer
 * dashboards are the business's own figures, so they need `reports.view`
 * (org-owner, org-admin; agents do not hold it, property owners have their
 * statements in /owner instead, P08-05), in the organization of the
 * current context, as a member of its workspace.
 *
 * Everything here is a read: open while the organization is suspended
 * (`reports.view` is kept, OrganizationWriteGuard), and in a support
 * session — the reports name tenants but carry no phone, email, bank
 * detail or payment reference. Bound to PortfolioReport in the provider.
 */
final readonly class ReportPolicy
{
    public function __construct(private OrganizationContext $context) {}

    public function viewAny(User $user): bool
    {
        if (! $this->context->has() || ! in_array($this->context->id(), $user->workspaceOrganizationIds(), true)) {
            return false;
        }

        try {
            return $user->hasPermissionTo(OrganizationPermission::ViewReports->value, OrganizationPermission::GUARD);
        } catch (PermissionDoesNotExist) {
            return false;
        }
    }

    /** Export a report (CSV, Excel) or the whole page (PDF). */
    public function export(User $user): bool
    {
        return $this->viewAny($user);
    }
}
