<?php

declare(strict_types=1);

namespace Modules\Handovers\Enums;

use App\Enums\Concerns\HasValues;
use App\Enums\InvitationStatus;

/**
 * A management handover's state (`management_handovers.status`,
 * BILLING_PAYMENTS.md § 7.2):
 *
 *     pending ──accept──▶ active      (the buildings and their live records move, one transaction)
 *     pending ──decline─▶ declined    (nothing moves)
 *     pending ──cancel──▶ cancelled   (the offering organization withdraws it)
 *     pending ──14 days─▶ expired     (the link expires too)
 *     active ──notice──▶ ending       (either side; the agency manages until the end date)
 *     ending ──withdraw notice──▶ active   (the side that gave it, before the end date)
 *     ending ──end date─▶ ended       (the live records move back)
 *
 * Any other move is refused. Changed only by
 * Modules\Handovers\Handovers\HandoverService (AGENT.md § 3 rule 5).
 */
enum HandoverStatus: string
{
    use HasValues;

    case Pending = 'pending';
    case Active = 'active';
    case Declined = 'declined';
    case Cancelled = 'cancelled';
    case Expired = 'expired';
    case Ending = 'ending';
    case Ended = 'ended';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Waiting for an answer',
            self::Active => 'Managed',
            self::Declined => 'Declined',
            self::Cancelled => 'Withdrawn',
            self::Expired => 'Expired',
            self::Ending => 'Ending (notice given)',
            self::Ended => 'Ended',
        };
    }

    /** Flux badge colour. */
    public function color(): string
    {
        return match ($this) {
            self::Pending => 'sky',
            self::Active => 'green',
            self::Ending => 'amber',
            self::Declined => 'red',
            self::Cancelled, self::Expired, self::Ended => 'zinc',
        };
    }

    /** The moves BILLING_PAYMENTS.md § 7.2 allows. */
    public function canMoveTo(self $next): bool
    {
        return match ($this) {
            self::Pending => in_array($next, [self::Active, self::Declined, self::Cancelled, self::Expired], true),
            self::Active => $next === self::Ending,
            self::Ending => in_array($next, [self::Active, self::Ended], true),
            self::Declined, self::Cancelled, self::Expired, self::Ended => false,
        };
    }

    /** Pending, active or ending: its buildings may be in no other handover. */
    public function isOpen(): bool
    {
        return in_array($this, [self::Pending, self::Active, self::Ending], true);
    }

    /** Accepted at some point: its buildings moved. */
    public function wasAccepted(): bool
    {
        return in_array($this, [self::Active, self::Ending, self::Ended], true);
    }

    /** @return list<string> */
    public static function openValues(): array
    {
        return [self::Pending->value, self::Active->value, self::Ending->value];
    }

    /** The state of the invitation that carries the offer's link. */
    public function invitationStatus(): InvitationStatus
    {
        return match ($this) {
            self::Pending => InvitationStatus::Pending,
            self::Declined => InvitationStatus::Declined,
            self::Cancelled => InvitationStatus::Cancelled,
            self::Expired => InvitationStatus::Expired,
            self::Active, self::Ending, self::Ended => InvitationStatus::Accepted,
        };
    }
}
