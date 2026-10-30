<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasValues;

/**
 * A GoProperty subscription's lifecycle (BILLING_PAYMENTS.md § 2; P06-01b,
 * docs/plans/P06-subtasks.md default 11). Changed only by
 * App\Subscriptions\SubscriptionService.
 */
enum SubscriptionStatus: string
{
    use HasValues;

    case Trialing = 'trialing';
    case Active = 'active';
    case PastDue = 'past_due';
    case Grace = 'grace';
    case Suspended = 'suspended';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Trialing => 'Free trial',
            self::Active => 'Active',
            self::PastDue => 'Payment overdue',
            self::Grace => 'Final days before suspension',
            self::Suspended => 'Suspended',
            self::Cancelled => 'Cancelled',
        };
    }

    /** @return list<self> */
    public function next(): array
    {
        return match ($this) {
            self::Trialing => [self::Active, self::Cancelled],
            self::Active => [self::PastDue, self::Cancelled],
            self::PastDue => [self::Active, self::Grace],
            self::Grace => [self::Active, self::Suspended],
            self::Suspended => [self::Active, self::Cancelled],
            self::Cancelled => [self::Active],
        };
    }

    public function canMoveTo(self $to): bool
    {
        return in_array($to, $this->next(), true);
    }

    /** The organization uses the plan's features (not while suspended or cancelled: it is read-only then). */
    public function isLive(): bool
    {
        return $this !== self::Cancelled;
    }
}
