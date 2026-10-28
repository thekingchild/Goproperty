<?php

declare(strict_types=1);

namespace Modules\Properties\Contracts;

/**
 * One owner profile of the signed-in user, as the owner portal may open it
 * now (OwnerPortalAccess): plain values, no model. `organizationId` is the
 * organization that manages the owner's buildings there.
 */
final readonly class PortalOwner
{
    public function __construct(
        public int $organizationId,
        public string $organizationName,
        public int $ownerProfileId,
        public string $ownerName,
    ) {}
}
