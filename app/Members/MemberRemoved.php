<?php

declare(strict_types=1);

namespace App\Members;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A member was taken out of an organization (Members::remove), sent inside
 * the removal's transaction, with that organization's context set, so a
 * listener's cleanup (e.g. the Properties module dropping the member's
 * building assignments, P02-03) commits or rolls back with the removal.
 * Plain ids only.
 */
final readonly class MemberRemoved
{
    use Dispatchable;

    public function __construct(
        public int $organizationId,
        public int $userId,
        public ?int $removedByUserId,
    ) {}
}
