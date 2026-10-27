<?php

declare(strict_types=1);

namespace Modules\Tenants\Portal;

use App\Models\Organization;
use App\Models\User;
use Modules\Tenants\Models\TenantProfile;

/** The account now linked to the tenant, and whether it was made just now. */
final readonly class AcceptedPortalInvitation
{
    public function __construct(
        public User $user,
        public Organization $organization,
        public TenantProfile $tenant,
        public bool $newAccount,
    ) {}
}
