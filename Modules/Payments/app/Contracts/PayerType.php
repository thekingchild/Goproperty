<?php

declare(strict_types=1);

namespace Modules\Payments\Contracts;

use App\Enums\Concerns\HasValues;

/**
 * Who paid (`payments.payer_type`): a person or a company, for the cash
 * thresholds of ONBOARDING_COMPLIANCE.md § 5.4 (₦5m individual, ₦10m
 * corporate; flags come with P06-09). Public for Contracts\PaymentRecorder.
 */
enum PayerType: string
{
    use HasValues;

    case Individual = 'individual';
    case Corporate = 'corporate';

    public function label(): string
    {
        return match ($this) {
            self::Individual => 'A person',
            self::Corporate => 'A company',
        };
    }
}
