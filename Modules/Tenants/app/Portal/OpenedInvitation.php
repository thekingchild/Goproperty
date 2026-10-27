<?php

declare(strict_types=1);

namespace Modules\Tenants\Portal;

use App\Models\Invitation;
use App\Models\Organization;
use Modules\Tenants\Models\TenantProfile;

/** A usable tenant invitation or sign-in link, with its tenant and organization, as its page shows it. */
final readonly class OpenedInvitation
{
    public function __construct(
        public Invitation $invitation,
        public TenantProfile $tenant,
        public Organization $organization,
    ) {}
}
