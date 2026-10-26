<?php

declare(strict_types=1);

namespace App\Hosting\Queue;

enum TickStopReason: string
{
    /** Every lane was empty or reached its per-tick cap. */
    case Drained = 'drained';

    /** The time budget ran out; the next minute's tick carries on. */
    case Budget = 'budget';

    case Memory = 'memory';

    case LostConnection = 'lost_connection';

    /** Another tick still holds the lock, so this one did nothing. */
    case Locked = 'locked';
}
