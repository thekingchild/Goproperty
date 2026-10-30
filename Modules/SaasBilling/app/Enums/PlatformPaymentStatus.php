<?php

declare(strict_types=1);

namespace Modules\SaasBilling\Enums;

use App\Enums\Concerns\HasValues;

/**
 * `platform_payments.status` (P05-03): pending → confirmed | failed | held.
 * Held: Paystack took money, but not as asked (amount, currency, mode); it
 * is never applied, platform staff review it (BILLING_PAYMENTS.md § 4.3 rule 2).
 */
enum PlatformPaymentStatus: string
{
    use HasValues;

    case Pending = 'pending';
    case Confirmed = 'confirmed';
    case Failed = 'failed';
    case Held = 'held';

    /** Settled for good: confirmed (credits added) or held for staff. A failed checkout may still turn out paid (then it is held). */
    public function isSettled(): bool
    {
        return in_array($this, [self::Confirmed, self::Held], true);
    }

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Waiting for payment',
            self::Confirmed => 'Paid',
            self::Failed => 'Not paid',
            self::Held => 'Under review',
        };
    }
}
