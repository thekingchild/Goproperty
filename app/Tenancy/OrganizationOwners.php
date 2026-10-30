<?php

declare(strict_types=1);

namespace App\Tenancy;

use App\Enums\OrganizationRole;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The people to tell about something that happened to an organization
 * (suspension, Transaction Tier decisions, expiring documents): its
 * members holding `org-owner` there who have an email address (phone-only
 * accounts cannot be emailed). Works with or without an organization
 * context.
 */
final class OrganizationOwners
{
    /** @return Collection<int, User> */
    public function withEmail(Organization $organization): Collection
    {
        return $this->holding($organization, [OrganizationRole::Owner])->whereNotNull('email')->values();
    }

    /**
     * Its owners, with or without an email address: who is sent GoProperty's
     * invoices (P06-02, BILLING_PAYMENTS.md § 3 step 4; in the app, and by
     * email when they have an address).
     *
     * @return Collection<int, User>
     */
    public function owners(Organization $organization): Collection
    {
        return $this->holding($organization, [OrganizationRole::Owner]);
    }

    /**
     * Its owners and administrators, with or without an email address (an
     * in-app notice reaches a phone-only account too): who is told the SMS
     * credits are running out (P05-03).
     *
     * @return Collection<int, User>
     */
    public function ownersAndAdministrators(Organization $organization): Collection
    {
        return $this->holding($organization, [OrganizationRole::Owner, OrganizationRole::Admin]);
    }

    /**
     * @param  list<OrganizationRole>  $roles
     * @return Collection<int, User>
     */
    private function holding(Organization $organization, array $roles): Collection
    {
        $holders = DB::table('model_has_roles')
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->whereIn('roles.name', array_map(static fn (OrganizationRole $role): string => $role->value, $roles))
            ->whereNull('roles.organization_id')
            ->where('model_has_roles.organization_id', $organization->getKey())
            ->where('model_has_roles.model_type', (new User)->getMorphClass())
            ->select('model_has_roles.model_id');

        $members = DB::table('organization_user')
            ->where('organization_id', $organization->getKey())
            ->select('user_id');

        return User::query()
            ->whereIn('id', $holders)
            ->whereIn('id', $members)
            ->orderBy('id')
            ->get();
    }
}
