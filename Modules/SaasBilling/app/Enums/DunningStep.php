<?php

declare(strict_types=1);

namespace Modules\SaasBilling\Enums;

use App\Enums\Concerns\HasValues;

/**
 * A notice of the dunning ladder (`dunning_notices.step`, P06-05;
 * BILLING_PAYMENTS.md § 6), and the template it is sent with.
 */
enum DunningStep: string
{
    use HasValues;

    /** An invoice is unpaid past its due date: the subscription is past due. */
    case PastDue = 'past_due';

    /** The final days before suspension: on the first day of grace and each day after. */
    case GraceWarning = 'grace_warning';

    /** Unpaid beyond the grace period: the organization is read-only. */
    case Suspended = 'suspended';

    /** Every 14 days while suspended. */
    case SuspensionReminder = 'suspension_reminder';

    /** 90 days suspended: the subscription ends. */
    case Cancelled = 'cancelled';

    /** The email template (P05-04). */
    public function template(): string
    {
        return 'billing.'.$this->value;
    }

    /** The SMS template (P05-02), for the steps that also go by SMS (§ 6: "SMS + email to owner"). */
    public function smsTemplate(): ?string
    {
        return $this->sendsSms() ? $this->template().'.sms' : null;
    }

    public function sendsSms(): bool
    {
        return $this === self::Suspended || $this === self::SuspensionReminder;
    }
}
