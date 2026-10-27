<?php

declare(strict_types=1);

namespace Modules\Leasing\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A draft lease became active: the unit is let (occupied) and the lease has its contract number. For a renewal, LeaseRenewed is sent for the lease it renews in the same transaction.
 * See LeaseEvent.
 */
final readonly class LeaseActivated extends LeaseEvent
{
    use Dispatchable;
}
