<?php

declare(strict_types=1);

namespace App\Sms\Credits;

use App\Enums\Concerns\HasValues;

/** Why an SMS credit entry was written (`sms_credit_entries.reason`, DATABASE_SCHEMA.md § 7; P05-03). */
enum CreditReason: string
{
    use HasValues;

    /** The plan's monthly allowance (or the difference after a bigger plan or allowance). */
    case MonthlyAllowance = 'monthly_allowance';
    /** A bundle bought in the app, once its payment is confirmed. */
    case Purchase = 'purchase';
    /** One SMS page sent (taken before GoText is called). */
    case Usage = 'usage';
    /** GoProperty staff, with a reason; or a credit given back for an SMS GoText refused. */
    case Adjustment = 'adjustment';
    /** Last month's allowance left unused. */
    case Expiry = 'expiry';

    public function label(): string
    {
        return match ($this) {
            self::MonthlyAllowance => 'Monthly allowance',
            self::Purchase => 'Bought',
            self::Usage => 'SMS sent',
            self::Adjustment => 'Adjustment',
            self::Expiry => 'Allowance expired',
        };
    }
}
