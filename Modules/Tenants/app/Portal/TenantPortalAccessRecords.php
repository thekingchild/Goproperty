<?php

declare(strict_types=1);

namespace Modules\Tenants\Portal;

use App\Models\User;
use App\Tenancy\OrganizationContext;
use Illuminate\Support\Facades\Gate;
use Modules\Tenants\Contracts\PortalTenant;
use Modules\Tenants\Contracts\TenantPortalAccess;
use Modules\Tenants\Models\TenantProfile;

/**
 * The Tenants module's answer to Contracts\TenantPortalAccess: the portal
 * path of Portal\PortalTenancies, given to other modules as plain values.
 */
final readonly class TenantPortalAccessRecords implements TenantPortalAccess
{
    public function __construct(
        private PortalTenancies $tenancies,
        private OrganizationContext $context,
    ) {}

    public function tenancies(User $user): array
    {
        return array_map(
            static fn (PortalTenancy $tenancy): PortalTenant => new PortalTenant($tenancy->organization, $tenancy->tenantProfileIds),
            $this->tenancies->of($user),
        );
    }

    public function profileIdsHere(User $user): array
    {
        $organizationId = $this->context->id();

        if (! $this->tenancies->opens($user, $organizationId)) {
            return [];
        }

        return array_values(TenantProfile::query()
            ->where('user_id', $user->id)
            ->orderBy('id')
            ->get()
            ->filter(static fn (TenantProfile $profile): bool => Gate::forUser($user)->allows('viewAsTenant', $profile))
            ->map(static fn (TenantProfile $profile): int => $profile->id)
            ->all());
    }
}
