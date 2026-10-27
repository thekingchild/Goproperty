<?php

declare(strict_types=1);

namespace Modules\Leasing\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A draft lease was made (a new lease, or the renewal of an active one). While only drafts wait for a vacant unit, the unit is reserved.
 * See LeaseEvent.
 */
final readonly class LeaseDrafted extends LeaseEvent
{
    use Dispatchable;
}
