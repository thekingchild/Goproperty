<?php

declare(strict_types=1);

namespace Modules\Properties\OwnerPortal;

use App\Models\Organization;
use App\Models\User;
use Modules\Properties\Models\OwnerProfile;

/** The account now linked to the owner, and whether it was made just now (P03-15). */
final readonly class AcceptedOwnerInvitation
{
    public function __construct(
        public User $user,
        public Organization $organization,
        public OwnerProfile $owner,
        public bool $newAccount,
    ) {}
}
