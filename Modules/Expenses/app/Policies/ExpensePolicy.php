<?php

declare(strict_types=1);

namespace Modules\Expenses\Policies;

use App\Enums\OrganizationPermission;
use App\Models\User;
use App\Tenancy\BlockedWhileImpersonating;
use App\Tenancy\OrganizationContext;
use Illuminate\Auth\Access\Response;
use Modules\Expenses\Models\Expense;
use Modules\Properties\Contracts\BuildingAccess;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;

/**
 * Expenses in the current organization (P03-12; ARCHITECTURE.md § 5
 * "Expenses"):
 *
 * - `expenses.view` (org-owner, org-admin, agent; kept while suspended)
 *   sees the expenses of buildings, and their receipts;
 * - `expenses.view_organization` (org-owner, org-admin; kept while
 *   suspended) also sees the organization's own expenses, linked to no
 *   building (office costs, salaries): agents never see those, narrowed or
 *   not;
 * - `expenses.record` (org-owner, org-admin, agent) records and edits
 *   expenses and adds receipts; an expense with no building also needs
 *   `expenses.view_organization`;
 * - `expenses.manage` (org-owner, org-admin) archives and restores
 *   expenses and archives receipts.
 *
 * Property owners and tenants never open the workspace (their expenses
 * reach them through reports, P08). Receipts are evidence (AGENT.md § 3
 * rule 7): no ability deletes an expense or a receipt for good.
 *
 * The central guards apply on top: in a suspended organization only the
 * reads pass (OrganizationWriteGuard: view…, export…). While GoProperty
 * staff impersonate, recording and editing an expense are allowed in write
 * mode (the organization's own bookkeeping, not tenant money; audited with
 * both identities); archiving (`delete`), adding or archiving a receipt
 * and opening a receipt file (it may show a payee's bank details or
 * phone) are refused in both modes.
 *
 * Agent narrowing (P02-03, decision 9): an agent assigned to buildings
 * sees and works on those buildings' expenses only; any other expense
 * answers 404 (Response::denyAsNotFound), checked before the ability's own
 * rule. An expense of a building that has left the organization (a
 * management handover, P03-14) stays here as history and is outside every
 * narrowed agent's buildings.
 */
final class ExpensePolicy
{
    public function __construct(
        private readonly OrganizationContext $context,
        private readonly BuildingAccess $access,
    ) {}

    public function viewAny(User $user): bool
    {
        return $this->holds($user, OrganizationPermission::ViewExpenses);
    }

    /** Export the expenses list (Lists\ExpenseList, already narrowed; no contact details). */
    public function export(User $user): bool
    {
        return $this->viewAny($user);
    }

    /** See the organization's own expenses (no building), in lists and on their pages. */
    public function viewOrganizationExpenses(User $user): bool
    {
        return $this->viewAny($user) && $this->holds($user, OrganizationPermission::ViewOrganizationExpenses);
    }

    public function view(User $user, Expense $expense): Response|bool
    {
        return $this->reachable($user, $expense) ?? $this->viewAny($user);
    }

    /** Open a receipt file (a short-lived signed link): it may show a payee's bank details or phone. */
    #[BlockedWhileImpersonating]
    public function viewReceiptFile(User $user, Expense $expense): Response|bool
    {
        return $this->view($user, $expense);
    }

    /** Record an expense (for which building: Recording\ExpenseBook checks the member's buildings). */
    public function create(User $user): bool
    {
        return $this->holds($user, OrganizationPermission::RecordExpenses);
    }

    /** Record an expense of the organization itself, linked to no building. */
    public function createUnlinked(User $user): bool
    {
        return $this->create($user) && $this->viewOrganizationExpenses($user);
    }

    /** Change an expense's details (not an archived one). */
    public function update(User $user, Expense $expense): Response|bool
    {
        return $this->reachable($user, $expense) ?? (! $expense->isArchived() && $this->create($user));
    }

    /** Add a receipt file to an expense (not an archived one). */
    #[BlockedWhileImpersonating]
    public function attachReceipt(User $user, Expense $expense): Response|bool
    {
        return $this->update($user, $expense);
    }

    /** "Remove" a receipt: archived with a reason, the file kept. */
    #[BlockedWhileImpersonating]
    public function archiveReceipt(User $user, Expense $expense): Response|bool
    {
        return $this->reachable($user, $expense) ?? $this->manages($user);
    }

    /** Archive the expense (a soft delete with a reason). */
    public function delete(User $user, Expense $expense): Response|bool
    {
        return $this->reachable($user, $expense) ?? (! $expense->isArchived() && $this->manages($user));
    }

    public function restore(User $user, Expense $expense): Response|bool
    {
        return $this->reachable($user, $expense) ?? ($expense->isArchived() && $this->manages($user));
    }

    /** Never: expenses are the organization's history (AGENT.md § 3 rule 7). */
    public function forceDelete(User $user, Expense $expense): bool
    {
        return false;
    }

    /**
     * Null when the expense is this organization's and the member sees it
     * (the ability's own rule decides); false for another organization's;
     * 404 for an expense with no building to a member who does not see the
     * organization's own expenses, and outside a narrowed agent's buildings.
     */
    private function reachable(User $user, Expense $expense): Response|false|null
    {
        if (! $this->context->has() || $expense->organization_id !== $this->context->id()) {
            return false;
        }

        $visible = $expense->property_id === null
            ? $this->holds($user, OrganizationPermission::ViewOrganizationExpenses)
            : $this->access->allows($user, $expense->property_id);

        return $visible ? null : Response::denyAsNotFound();
    }

    private function manages(User $user): bool
    {
        return $this->holds($user, OrganizationPermission::ManageExpenses);
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
