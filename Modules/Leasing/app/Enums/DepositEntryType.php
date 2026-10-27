<?php

declare(strict_types=1);

namespace Modules\Leasing\Enums;

use App\Enums\Concerns\HasValues;

/**
 * Kinds of lease deposit entries (`lease_deposit_entries.type`, P03
 * decision 5). Records only: GoProperty never holds the money (AGENT.md
 * § 3 rule 8). The amount's sign follows the kind: held and carried in add
 * to the balance; carried out, refunded and kept take from it.
 */
enum DepositEntryType: string
{
    use HasValues;

    /** The deposit taken when the lease was activated. */
    case Held = 'held';

    /** The previous lease's deposit, moved to its renewal. */
    case CarriedIn = 'carried_in';

    /** Moved to the renewal lease. */
    case CarriedOut = 'carried_out';

    /** Paid back to the tenant at move-out. */
    case Refunded = 'refunded';

    /** Kept by the landlord at move-out (damage, unpaid charges), with a note. */
    case Kept = 'kept';

    public function label(): string
    {
        return match ($this) {
            self::Held => 'Deposit taken',
            self::CarriedIn => 'Carried over from the previous lease',
            self::CarriedOut => 'Carried over to the renewal',
            self::Refunded => 'Refunded to the tenant',
            self::Kept => 'Kept',
        };
    }

    /** Whether the entry adds to the balance (else it takes from it). */
    public function adds(): bool
    {
        return $this === self::Held || $this === self::CarriedIn;
    }
}
