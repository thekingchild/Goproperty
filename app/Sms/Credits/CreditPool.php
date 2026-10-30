<?php

declare(strict_types=1);

namespace App\Sms\Credits;

use App\Enums\Concerns\HasValues;

/**
 * Where credits sit (P05-03; BILLING_PAYMENTS.md § 1.2): the month's
 * allowance, which expires when the next one is granted, or purchased
 * credits, which never expire. Sending takes allowance credits first.
 */
enum CreditPool: string
{
    use HasValues;

    case Allowance = 'allowance';
    case Purchased = 'purchased';

    public function label(): string
    {
        return match ($this) {
            self::Allowance => 'Monthly allowance',
            self::Purchased => 'Purchased',
        };
    }
}
