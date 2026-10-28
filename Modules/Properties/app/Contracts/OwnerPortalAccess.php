<?php

declare(strict_types=1);

namespace Modules\Properties\Contracts;

use App\Models\User;
use Closure;

/**
 * The owner portal's "records linked to my user" path (ARCHITECTURE.md
 * § 4, P03-15) for other modules' /owner pages (the owner statements,
 * P08-05): which owner profiles the user follows, each checked by
 * OwnerProfilePolicy::viewAsOwner inside its organization (linked to the
 * user, not archived, `property-owner` held there; while GoProperty staff
 * impersonate, only the support session's organization). Never the
 * `owner_profiles` table itself. Bound in PropertiesServiceProvider.
 */
interface OwnerPortalAccess
{
    /**
     * The user's owner profiles the portal may open now, by organization
     * name.
     *
     * @return list<PortalOwner>
     */
    public function owners(User $user): array;

    /**
     * $callback run inside the organization of the user's owner profile
     * $ownerProfileId, or null (the page answers 404) when it is not one of
     * the user's, or the portal may not open it now.
     *
     * @template T
     *
     * @param  Closure(PortalOwner): T  $callback
     * @return T|null
     */
    public function within(User $user, int $ownerProfileId, Closure $callback): mixed;
}
