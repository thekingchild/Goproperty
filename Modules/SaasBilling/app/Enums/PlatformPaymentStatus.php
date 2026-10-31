<?php

declare(strict_types=1);

namespace Modules\SaasBilling\Enums;

use App\Enums\Concerns\HasValues;

/**
 * `platform_payments.status` (P05-03): pending → confirmed | failed | held.
 * Held: Paystack took money, but not as asked (amount, currency, mode); it
 * is never applied, platform staff review it (BILLING_PAYMENTS.md § 4.3 rule 2).
 * A bank transfer (P06-04, § 5) waits as pending until platform staff
 * confirm it or reject it (rejected: with the reason, kept as it is).
 */
enum PlatformPaymentStatus: string
{
    use HasValues;

    case Pending = 'pending';
    case Confirmed = 'confirmed';
    case Failed = 'failed';
    case Held = 'held';
    case Rejected = 'rejected';

    /**
     * Settled for good: confirmed, held for staff, or a bank transfer staff
     * rejected. A failed checkout may still turn out paid (then it is held).
     */
    public function isSettled(): bool
    {
        return in_array($this, [self::Confirmed, self::Held, self::Rejected], true);
    }

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Waiting for payment',
            self::Confirmed => 'Paid',
            self::Failed => 'Not paid',
            self::Held => 'Under review',
            self::Rejected => 'Not accepted',
        };
    }
}
