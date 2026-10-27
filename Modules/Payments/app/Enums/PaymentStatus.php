<?php

declare(strict_types=1);

namespace Modules\Payments\Enums;

use App\Enums\Concerns\HasValues;

/**
 * A payment's state (`payments.status`, DATABASE_SCHEMA.md § 4), changed
 * only by Payments\PaymentService (AGENT.md § 3 rule 5):
 *
 *   pending_confirmation → confirmed (allocated to bills, the rest to credit)
 *   pending_confirmation → rejected (with a reason; nothing is allocated)
 *
 * `confirmed` and `rejected` are final here. A confirmed payment is never
 * deleted or edited (integrity rule 3); undoing one is a reversal to
 * `refunded` through a refund/credit record, which is not built yet.
 */
enum PaymentStatus: string
{
    use HasValues;

    case PendingConfirmation = 'pending_confirmation';
    case Confirmed = 'confirmed';
    case Rejected = 'rejected';
    case Refunded = 'refunded';

    public function label(): string
    {
        return match ($this) {
            self::PendingConfirmation => 'Waiting for confirmation',
            self::Confirmed => 'Confirmed',
            self::Rejected => 'Rejected',
            self::Refunded => 'Refunded',
        };
    }

    /** Flux badge colour. */
    public function color(): string
    {
        return match ($this) {
            self::PendingConfirmation => 'amber',
            self::Confirmed => 'green',
            self::Rejected => 'red',
            self::Refunded => 'zinc',
        };
    }

    public function isPending(): bool
    {
        return $this === self::PendingConfirmation;
    }
}
