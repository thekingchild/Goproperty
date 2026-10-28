<?php

declare(strict_types=1);

namespace Modules\Properties\OwnerPortal;

use App\Models\Organization;

/**
 * One organization that manages buildings of the signed-in owner, as the
 * owner portal sees it (OwnerPortfolios, P03-15): the organization and the
 * user's owner profiles there, each loaded inside the organization and
 * allowed by OwnerProfilePolicy::viewAsOwner. Usually one profile (one per
 * user in an organization).
 */
final readonly class OwnerPortfolio
{
    /** @param  list<int>  $ownerProfileIds */
    public function __construct(
        public Organization $organization,
        public array $ownerProfileIds,
    ) {}
}
