<?php

declare(strict_types=1);

namespace Modules\Collections\Policies;

use App\Enums\OrganizationPermission;
use App\Models\User;
use App\Tenancy\BlockedWhileImpersonating;
use App\Tenancy\OrganizationContext;
use Modules\Collections\Models\BankStatementImport;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;

/**
 * Uploaded bank statements and their lines, in the current organization
 * (ONBOARDING_COMPLIANCE.md § 4.1, ARCHITECTURE.md § 5 "Bank statements"):
 * org-owners and org-admins only. `statements.view` lists the statements,
 * opens one with its report and lines, and exports both lists (no contact
 * details: dates, narrations, amounts); `statements.import` uploads one.
 *
 * Agents hold neither (P03-07a decision): a statement is the whole
 * account's history, every building's rent and the agency's own
 * outgoings, and its lines carry no building to narrow by (P02-03); the
 * payments matched from it reach agents through the payments pages,
 * narrowed by tenant. Property owners and tenants never open the workspace.
 *
 * The central guards apply on top: `statements.import` is dropped while
 * the organization is suspended and whenever staff impersonate; uploading
 * is also marked #[BlockedWhileImpersonating]. Staff may look at
 * statements and export the lists to help, but never download the
 * uploaded file (it prints the full account number, which Settings →
 * Payment collection never shows them either). A statement is evidence
 * and its lines history: never edited or deleted.
 */
final class BankStatementImportPolicy
{
    public function __construct(private readonly OrganizationContext $context) {}

    public function viewAny(User $user): bool
    {
        return $this->holds($user, OrganizationPermission::ViewStatements);
    }

    /** Export the statements list and a statement's lines (P02-07). */
    public function export(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function view(User $user, BankStatementImport $statement): bool
    {
        return $this->belongsHere($statement) && $this->viewAny($user);
    }

    /** Download the uploaded file (a signed, short-lived link). */
    #[BlockedWhileImpersonating]
    public function downloadFile(User $user, BankStatementImport $statement): bool
    {
        return $this->view($user, $statement);
    }

    /** Upload a statement. */
    #[BlockedWhileImpersonating]
    public function create(User $user): bool
    {
        return $this->holds($user, OrganizationPermission::ImportStatements);
    }

    /** Statements are evidence: never edited. */
    public function update(User $user, BankStatementImport $statement): bool
    {
        return false;
    }

    /** Statements are evidence: never deleted. */
    public function delete(User $user, BankStatementImport $statement): bool
    {
        return false;
    }

    private function belongsHere(BankStatementImport $statement): bool
    {
        return $this->context->has() && $statement->organization_id === $this->context->id();
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
