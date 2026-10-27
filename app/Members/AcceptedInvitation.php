<?php

declare(strict_types=1);

namespace App\Members;

use App\Models\Organization;
use App\Models\User;

/** The account that accepted an invitation and the organization it joined. */
final readonly class AcceptedInvitation
{
    public function __construct(
        public User $user,
        public Organization $organization,
    ) {}
}
