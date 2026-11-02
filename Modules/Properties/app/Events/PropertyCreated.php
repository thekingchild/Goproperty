<?php

declare(strict_types=1);

namespace Modules\Properties\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A building was added (by a member, or by an import), or an import brought
 * back one its rollback had removed (`restored`). For the Building Group,
 * which opens the building's group (P07-01, ARCHITECTURE.md § 6.8). Sent
 * after the transaction that added it commits, so a rolled-back add sends
 * nothing. Plain values only: listeners in other modules never see the
 * Properties models.
 */
final readonly class PropertyCreated implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public int $organizationId,
        public int $propertyId,
        public bool $restored = false,
    ) {}
}
