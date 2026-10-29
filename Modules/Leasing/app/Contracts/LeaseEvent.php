<?php

declare(strict_types=1);

namespace Modules\Leasing\Contracts;

use Carbon\CarbonImmutable;

/**
 * One moment of a lease's history as the owner portal's timeline shows it
 * (OwnerLeases, P03-16), worked out from what the lease records (its dates,
 * the lease it renews, its termination): `type` is one of the constants,
 * `label` a short English phrase (translate it in the view), `on` a
 * calendar day (Lagos) and `detail` an optional line under it (a rent
 * change, a termination reason).
 */
final readonly class LeaseEvent
{
    /** The tenant moved in: the start of a lease that renews none. */
    public const string MOVED_IN = 'moved_in';

    /** The lease renews the previous one: the tenant stayed. */
    public const string RENEWED = 'renewed';

    /** The renewal changed the rent. */
    public const string RENT_CHANGED = 'rent_changed';

    /** Ended early by the agency, with a reason. */
    public const string TERMINATED = 'terminated';

    /** The tenant moved out: the lease expired or was terminated, and no renewal followed. */
    public const string MOVED_OUT = 'moved_out';

    public function __construct(
        public string $type,
        public string $label,
        public CarbonImmutable $on,
        public ?string $detail = null,
    ) {}
}
