<?php

declare(strict_types=1);

namespace Modules\Billing\Policies;

use App\Enums\OrganizationPermission;
use App\Models\User;
use App\Tenancy\OrganizationContext;
use Modules\Billing\Models\UtilityType;

/**
 * The organization's utility types (P03-06a; ARCHITECTURE.md § 5
 * "Utilities"): `utilities.view` (org-owner, org-admin, agent; kept while
 * suspended) sees them and exports the list; `utilities.manage` (org-owner,
 * org-admin: what units are charged is pricing) adds and edits active
 * types, archives (`delete`) and restores them. Changes go through
 * Utilities\UtilityTypes, which checks this policy again. Types are
 * organization-wide, so agent narrowing does not apply; another
 * organization's type never reaches a policy (the organization scope hides
 * it), and is refused here if handed over anyway.
 *
 * Central guards on top: a suspended organization only reads
 * (`utilities.manage` is dropped); while staff impersonate, archiving is a
 * `delete` ability and refused in both modes (PLATFORM_CONSOLE.md § 6),
 * editing is allowed in write mode, audited with both identities.
 */
final class UtilityTypePolicy
{
    use ChecksOrganizationPermission;

    public function __construct(private readonly OrganizationContext $context) {}

    public function viewAny(User $user): bool
    {
        return $this->holds($user, OrganizationPermission::ViewUtilities);
    }

    /** Export the utility types list (P02-07; no contact details). */
    public function export(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function view(User $user, UtilityType $type): bool
    {
        return $this->ours($type) && $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->holds($user, OrganizationPermission::ManageUtilities);
    }

    /** Edit an active type (an archived one is restored first). */
    public function update(User $user, UtilityType $type): bool
    {
        return $this->ours($type) && $type->active && $this->create($user);
    }

    /** Archive: units stop being charged it (P03-06b), their settings and readings stay. */
    public function delete(User $user, UtilityType $type): bool
    {
        return $this->update($user, $type);
    }

    public function restore(User $user, UtilityType $type): bool
    {
        return $this->ours($type) && ! $type->active && $this->create($user);
    }

    private function ours(UtilityType $type): bool
    {
        return $this->context->has() && $type->organization_id === $this->context->id();
    }
}
