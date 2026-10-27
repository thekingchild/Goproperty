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
        $owners = DB::table('model_has_roles')
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->where('roles.name', OrganizationRole::Owner->value)
            ->whereNull('roles.organization_id')
            ->where('model_has_roles.organization_id', $organization->getKey())
            ->where('model_has_roles.model_type', (new User)->getMorphClass())
            ->select('model_has_roles.model_id');

        $members = DB::table('organization_user')
            ->where('organization_id', $organization->getKey())
            ->select('user_id');

        return User::query()
            ->whereIn('id', $owners)
            ->whereIn('id', $members)
            ->whereNotNull('email')
            ->orderBy('id')
            ->get();
    }
}
