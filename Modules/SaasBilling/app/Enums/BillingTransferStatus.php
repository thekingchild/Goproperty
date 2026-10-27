<?php

declare(strict_types=1);

namespace Modules\SaasBilling\Enums;

use App\Enums\Concerns\HasValues;
use App\Enums\InvitationStatus;

/**
 * A billing transfer's state (`billing_transfer_requests.status`,
 * BILLING_PAYMENTS.md § 7):
 *
 *     pending ──accept──▶ completed
 *     pending ──decline─▶ declined
 *     pending ──cancel──▶ cancelled   (the offering organization withdraws it)
 *     pending ──14 days─▶ expired
 *
 * Only a pending transfer moves, and only once; the four others are final
 * (a reversal is a new transfer the other way). Changed only by
 * Modules\SaasBilling\Transfers\BillingTransferService.
 */
enum BillingTransferStatus: string
{
    use HasValues;

    case Pending = 'pending';
    case Completed = 'completed';
    case Declined = 'declined';
    case Cancelled = 'cancelled';
    case Expired = 'expired';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Waiting for an answer',
            self::Completed => 'Accepted',
            self::Declined => 'Declined',
            self::Cancelled => 'Withdrawn',
            self::Expired => 'Expired',
        };
    }

    public function canMoveTo(self $next): bool
    {
        return $this === self::Pending && $next !== self::Pending;
    }

    public function isFinal(): bool
    {
        return $this !== self::Pending;
    }

    /** The state of the invitation that carries the transfer's link. */
    public function invitationStatus(): InvitationStatus
    {
        return match ($this) {
            self::Pending => InvitationStatus::Pending,
            self::Completed => InvitationStatus::Accepted,
            self::Declined => InvitationStatus::Declined,
            self::Cancelled => InvitationStatus::Cancelled,
            self::Expired => InvitationStatus::Expired,
        };
    }
}
