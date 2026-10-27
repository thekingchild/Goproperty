<?php

declare(strict_types=1);

namespace Modules\Tenants\Portal;

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Modules\Tenants\Models\TenantProfile;

/**
 * The tenant portal's policy check for a record it shows or opens (P03-11a,
 * P03-11b): the record's tenant profile, loaded inside the record's
 * organization (the caller runs inside it, through PortalTenancies), against
 * a TenantProfilePolicy portal ability — viewAsTenant to show it,
 * viewInvoiceAsTenant / downloadAsTenant to open it as printed or as a file.
 * A profile that is not the user's own answers 404; a document while
 * GoProperty staff impersonate answers 403 (the central guard).
 */
final class PortalChecks
{
    /** @throws AuthorizationException */
    public function authorize(User $user, string $ability, int $tenantProfileId): TenantProfile
    {
        $profile = TenantProfile::query()->find($tenantProfileId);

        abort_unless($profile instanceof TenantProfile, 404);

        Gate::forUser($user)->authorize($ability, $profile);

        return $profile;
    }

    public function allows(User $user, string $ability, int $tenantProfileId): bool
    {
        $profile = TenantProfile::query()->find($tenantProfileId);

        return $profile instanceof TenantProfile && Gate::forUser($user)->allows($ability, $profile);
    }
}
