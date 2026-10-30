<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasValues;

/** How often a GoProperty subscription is invoiced (BILLING_PAYMENTS.md § 1.4: annual = pay 10 months, get 12). */
enum BillingCycle: string
{
    use HasValues;

    case Monthly = 'monthly';
    case Annual = 'annual';

    public function label(): string
    {
        return $this === self::Monthly ? 'Monthly' : 'Yearly';
    }
}
