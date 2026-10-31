<?php

declare(strict_types=1);

namespace Modules\SaasBilling\Enums;

use App\Enums\Concerns\HasValues;

/**
 * A refund of a payment into GoProperty's account (P06-07b;
 * docs/plans/P06-subtasks.md defaults 57–59):
 *
 * - `requested`: waiting for a second staff member's approval (above the
 *   configured amount); it holds back its amount from other refunds.
 * - `not_approved`: the approval was rejected or expired; nothing refunded.
 * - `processing`: Paystack accepted it; its effects (the invoice reopened)
 *   apply from here.
 * - `processed`: the money went back (Paystack says so, or GoProperty's
 *   own bank transfer was recorded).
 * - `failed`: Paystack could not refund it; staff settle it by hand.
 */
enum RefundStatus: string
{
    use HasValues;

    case Requested = 'requested';
    case NotApproved = 'not_approved';
    case Processing = 'processing';
    case Processed = 'processed';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Requested => 'Waiting for approval',
            self::NotApproved => 'Not approved',
            self::Processing => 'Processing at Paystack',
            self::Processed => 'Refunded',
            self::Failed => 'Failed',
        };
    }

    /** The money is (or is being) given back. */
    public function refunds(): bool
    {
        return $this === self::Processing || $this === self::Processed;
    }

    /** Counts against what is left to refund on the payment. */
    public function holdsAmount(): bool
    {
        return $this === self::Requested || $this->refunds();
    }

    /** @return list<string> */
    public static function holdingValues(): array
    {
        return [self::Requested->value, self::Processing->value, self::Processed->value];
    }
}
