<?php

declare(strict_types=1);

namespace Modules\OnlinePayments\Enums;

/**
 * An online payment GoProperty started in the organization's Paystack
 * account (Models\PaystackTransaction):
 *
 * - initialized: sent to Paystack; the tenant may still be paying;
 * - confirmed: Paystack confirmed it when asked (verify-by-fetch), the
 *   amount and currency were the ones asked for, and the payment was
 *   recorded and confirmed;
 * - held: paid, but not as asked (amount, currency, or the bill no longer
 *   owing it): recorded for a member to review, never confirmed by itself;
 * - failed: Paystack says the charge failed or was reversed.
 *
 * Confirmed, held and failed are final: a replayed webhook changes nothing.
 */
enum TransactionStatus: string
{
    case Initialized = 'initialized';
    case Confirmed = 'confirmed';
    case Held = 'held';
    case Failed = 'failed';

    public function isFinal(): bool
    {
        return $this !== self::Initialized;
    }

    public function label(): string
    {
        return match ($this) {
            self::Initialized => 'Started',
            self::Confirmed => 'Confirmed',
            self::Held => 'Held for review',
            self::Failed => 'Failed',
        };
    }
}
