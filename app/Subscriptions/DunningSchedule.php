<?php

declare(strict_types=1);

namespace App\Subscriptions;

use App\Enums\SubscriptionStatus;
use App\Models\Subscription;
use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * When an unpaid subscription moves along the dunning ladder
 * (BILLING_PAYMENTS.md § 6; P06-05). Lagos dates. Each step counts from the
 * day the step before it happened, so a customer always gets its full
 * 7 days past due and 7 days of grace, with every warning, even when the
 * hourly job could not run for a while (docs/plans/P06-subtasks.md
 * default 38):
 *
 * - an invoice open past its due date: `past_due` the next day;
 * - 7 days after that: `grace` (a warning every day);
 * - 7 days after that (14 days past due): `suspended`, read-only;
 * - every 14 days suspended: a reminder;
 * - 90 days suspended: `cancelled`.
 *
 * Read by SaasBilling's ladder (which moves the subscription) and by the
 * workspace banner (which says when the next step comes).
 */
final class DunningSchedule
{
    public const int GRACE_AFTER_DAYS = 7;

    public const int SUSPEND_AFTER_DAYS = 7;

    public const int REMIND_EVERY_DAYS = 14;

    public const int CANCEL_AFTER_DAYS = 90;

    /** The Lagos day a moment falls on. */
    public static function day(DateTimeInterface $moment): CarbonImmutable
    {
        return CarbonImmutable::instance($moment)->setTimezone('Africa/Lagos')->startOfDay();
    }

    /** The day a past-due subscription enters its grace period. */
    public static function graceDate(Subscription $subscription): ?CarbonImmutable
    {
        return $subscription->past_due_at !== null ? self::day($subscription->past_due_at)->addDays(self::GRACE_AFTER_DAYS) : null;
    }

    /** The day an unpaid subscription is suspended, if nothing is paid before. */
    public static function suspensionDate(Subscription $subscription): ?CarbonImmutable
    {
        return match ($subscription->status) {
            SubscriptionStatus::PastDue => self::graceDate($subscription)?->addDays(self::SUSPEND_AFTER_DAYS),
            SubscriptionStatus::Grace => $subscription->grace_started_at !== null ? self::day($subscription->grace_started_at)->addDays(self::SUSPEND_AFTER_DAYS) : null,
            default => null,
        };
    }

    /** The day a suspended subscription is cancelled, if nothing is paid before. */
    public static function cancellationDate(Subscription $subscription): ?CarbonImmutable
    {
        return $subscription->status === SubscriptionStatus::Suspended && $subscription->suspended_at !== null
            ? self::day($subscription->suspended_at)->addDays(self::CANCEL_AFTER_DAYS)
            : null;
    }
}
