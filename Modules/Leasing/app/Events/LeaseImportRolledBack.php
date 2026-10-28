<?php

declare(strict_types=1);

namespace Modules\Leasing\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * An imported lease was archived by its import's rollback (P03-13a,
 * LeaseService::rollBackImport): `unitStatus` frees the unit when no other
 * lease holds it. See LeaseEvent.
 */
final readonly class LeaseImportRolledBack extends LeaseEvent
{
    use Dispatchable;
}
