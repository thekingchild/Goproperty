<?php

declare(strict_types=1);

namespace Modules\Billing\Policies;

use App\Enums\OrganizationPermission;
use App\Models\User;
use App\Tenancy\BlockedWhileImpersonating;
use App\Tenancy\OrganizationContext;
use Illuminate\Auth\Access\Response;
use Modules\Billing\Enums\BillStatus;
use Modules\Billing\Models\Bill;
use Modules\Properties\Contracts\BuildingAccess;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;

/**
 * Bills in the current organization (ARCHITECTURE.md § 5): `bills.view`
 * (org-owner, org-admin, agent; kept while suspended) sees them and
 * exports the list (tenant names and amounts, no contact details);
 * `bills.manage` (the same three) voids a bill (through
 * Bills\BillService, which checks this policy again). Property owners and
 * tenants never open the workspace (their portals come in P03-11 and
 * P03-15); another organization's bills never reach a policy (the
 * organization scope hides them).
 *
 * The central guards apply on top: in a suspended organization only the
 * reads pass (OrganizationWriteGuard: `bills.manage` is dropped); while
 * GoProperty staff impersonate, `bills.manage` and every `void…` ability
 * are refused in both modes (ImpersonationGuard, PLATFORM_CONSOLE.md § 6:
 * issuing and voiding bills are money actions).
 *
 * Agent narrowing (P02-03, decision 9): an agent assigned to buildings
 * sees those buildings' bills only (Contracts\BuildingAccess on
 * `bills.property_id`); any other bill answers 404, checked before the
 * ability's own rule. A bill with no building (none yet: rent bills
 * always have one) is seen by every member who sees all buildings.
 */
final class BillPolicy
{
    public function __construct(
        private readonly OrganizationContext $context,
        private readonly BuildingAccess $access,
    ) {}

    public function viewAny(User $user): bool
    {
        return $this->holds($user, OrganizationPermission::ViewBills);
    }

    /** Export the bills list (P02-07; Lists\BillList, already narrowed; no contact details). */
    public function export(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function view(User $user, Bill $bill): Response|bool
    {
        return $this->reachable($user, $bill) ?? $this->viewAny($user);
    }

    /**
     * See the bill's invoice as printed, as a web page or its PDF
     * (P03-05c). Whoever sees the bill may print it (a read: allowed while
     * suspended); staff impersonating a member may not, since it carries
     * the tenant's contact details (PLATFORM_CONSOLE.md § 6, as for the
     * lease contract and list exports with contacts).
     */
    #[BlockedWhileImpersonating]
    public function viewInvoice(User $user, Bill $bill): Response|bool
    {
        return $this->reachable($user, $bill) ?? $this->viewAny($user);
    }

    /** Void a bill that asks for money and has no payment applied (a money action). */
    public function void(User $user, Bill $bill): Response|bool
    {
        return $this->reachable($user, $bill)
            ?? ($bill->status !== BillStatus::Void && ! $bill->paid_kobo->isPositive() && $this->holds($user, OrganizationPermission::ManageBills));
    }

    /**
     * Null when the bill is this organization's and in the member's
     * buildings (the ability's own rule decides); false for another
     * organization's bill; 404 outside a narrowed agent's buildings.
     */
    private function reachable(User $user, Bill $bill): Response|false|null
    {
        if (! $this->context->has() || $bill->organization_id !== $this->context->id()) {
            return false;
        }

        if ($bill->property_id === null) {
            return $this->access->limitedTo($user) === null ? null : Response::denyAsNotFound();
        }

        return $this->access->allows($user, $bill->property_id) ? null : Response::denyAsNotFound();
    }

    private function holds(User $user, OrganizationPermission $permission): bool
    {
        if (! $this->context->has() || ! in_array($this->context->id(), $user->workspaceOrganizationIds(), true)) {
            return false;
        }

        try {
            return $user->hasPermissionTo($permission->value, OrganizationPermission::GUARD);
        } catch (PermissionDoesNotExist) {
            return false;
        }
    }
}
