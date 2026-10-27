<?php

declare(strict_types=1);

namespace Modules\Tenants\Portal;

use App\Models\User;
use Modules\Tenants\Models\TenantProfile;
use Modules\Tenants\Profiles\TenantProfiles;

/**
 * Writes portal-access changes to the hash-chained audit log under the
 * tenants' log (`tenants`), the tenant as subject, so they sit in the
 * tenant's history with the profile changes. Never the link or its token.
 */
final class PortalAccessAudit
{
    /** @param  array<string, mixed>  $properties */
    public function record(string $event, TenantProfile $tenant, User $by, string $description, array $properties = []): void
    {
        activity(TenantProfiles::LOG)
            ->event($event)
            ->performedOn($tenant)
            ->causedBy($by)
            ->withProperties(['tenant' => $tenant->fullName(), ...$properties])
            ->log($description);
    }
}
