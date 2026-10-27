<?php

declare(strict_types=1);

namespace Modules\Properties\Assignments;

use App\Enums\OrganizationRole;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Modules\Properties\Contracts\BuildingAccess;
use Modules\Properties\Models\AgentAssignment;
use Modules\Properties\Models\OwnerProfile;
use Modules\Properties\Models\Property;
use Modules\Properties\Models\Unit;

/**
 * Agent narrowing by building (ARCHITECTURE.md § 5: "`agent` visibility
 * can be narrowed by building assignment"). Narrowing is optional, per
 * agent: an agent (holding `agent` in the current organization, and
 * neither `org-owner` nor `org-admin`, who always see everything) with at
 * least one assignment there sees and works on the assigned buildings
 * only, their units, and the owners of those buildings; an agent with no
 * assignment sees every building, as before P02-03.
 *
 * Used by the policies (PropertyPolicy, UnitPolicy, OwnerProfilePolicy:
 * another building answers 404) and by every list of the module
 * (buildings(), units(), owners() add the same limit to a query), and by
 * other modules through Contracts\BuildingAccess. Roles are read through
 * the user's organization roles (the current OrganizationContext), and the
 * assignments through the organization scope, so only the current
 * organization's assignments count; a removed member's assignments are
 * deleted with the membership (DropRemovedMemberAssignments).
 */
final readonly class AgentNarrowing implements BuildingAccess
{
    public function limitedTo(User $user): ?array
    {
        if (! self::narrowable($user)) {
            return null;
        }

        $ids = array_values(array_map(
            static fn (mixed $id): int => (int) $id,
            AgentAssignment::query()->where('user_id', $user->id)->orderBy('property_id')->pluck('property_id')->all(),
        ));

        return $ids === [] ? null : $ids;
    }

    public function allows(User $user, int $propertyId): bool
    {
        $ids = $this->limitedTo($user);

        return $ids === null || in_array($propertyId, $ids, true);
    }

    /**
     * Limit a query of buildings to the member's.
     *
     * @param  Builder<Property>  $query
     * @return Builder<Property>
     */
    public function buildings(Builder $query, User $user): Builder
    {
        $ids = $this->limitedTo($user);

        return $ids === null ? $query : $query->whereIn($query->getModel()->qualifyColumn('id'), $ids);
    }

    /**
     * Limit a query of units to those of the member's buildings.
     *
     * @param  Builder<Unit>  $query
     * @return Builder<Unit>
     */
    public function units(Builder $query, User $user): Builder
    {
        $ids = $this->limitedTo($user);

        return $ids === null ? $query : $query->whereIn($query->getModel()->qualifyColumn('property_id'), $ids);
    }

    /**
     * Limit a query of owner profiles to the owners of the member's
     * buildings (owners are not assigned themselves; P02-03 decision).
     *
     * @param  Builder<OwnerProfile>  $query
     * @return Builder<OwnerProfile>
     */
    public function owners(Builder $query, User $user): Builder
    {
        $ids = $this->limitedTo($user);

        return $ids === null ? $query : $query->whereHas('properties', fn (Builder $buildings) => $buildings->whereIn('properties.id', $ids));
    }

    /** Whether the member is an agent here who is neither owner nor administrator (only they are ever narrowed). */
    public static function narrowable(User $user): bool
    {
        return $user->hasRole(OrganizationRole::Agent->value)
            && ! $user->hasAnyRole(OrganizationRole::Owner->value, OrganizationRole::Admin->value);
    }
}
