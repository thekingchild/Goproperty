<?php

declare(strict_types=1);

namespace Modules\Properties\OwnerPortal;

use App\Models\Invitation;
use App\Models\Organization;
use Modules\Properties\Models\OwnerProfile;

/** A usable owner invitation, with its owner and organization, as its page shows it (P03-15). */
final readonly class OpenedOwnerInvitation
{
    public function __construct(
        public Invitation $invitation,
        public OwnerProfile $owner,
        public Organization $organization,
    ) {}
}
