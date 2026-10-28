<?php

declare(strict_types=1);

namespace Modules\Properties\OwnerPortal;

use App\Models\User;
use Modules\Properties\Models\OwnerProfile;
use Modules\Properties\Owners\OwnerProfiles;

/**
 * Writes owner-portal access changes to the hash-chained audit log under
 * the owners' log (`owners`), the owner as subject, so they sit in the
 * owner's history with the profile changes (P03-15). Never the link or its
 * token.
 */
final class OwnerAccessAudit
{
    /** @param  array<string, mixed>  $properties */
    public function record(string $event, OwnerProfile $owner, User $by, string $description, array $properties = []): void
    {
        activity(OwnerProfiles::LOG)
            ->event($event)
            ->performedOn($owner)
            ->causedBy($by)
            ->withProperties(['owner' => $owner->display_name, ...$properties])
            ->log($description);
    }
}
