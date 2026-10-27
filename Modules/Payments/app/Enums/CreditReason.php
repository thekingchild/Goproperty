<?php

declare(strict_types=1);

namespace Modules\Payments\Enums;

use App\Enums\Concerns\HasValues;

/**
 * Why a tenant credit entry was written (`tenant_credit_entries.reason`,
 * DATABASE_SCHEMA.md § 4): + overpayment (a confirmed payment beyond the
 * tenant's open bills), − applied_to_bill (taken for a newly issued bill).
 * `refund` and `adjustment` are reserved for later work (reversals,
 * imported opening credit, P03-13b).
 */
enum CreditReason: string
{
    use HasValues;

    case Overpayment = 'overpayment';
    case AppliedToBill = 'applied_to_bill';
    case Refund = 'refund';
    case Adjustment = 'adjustment';

    public function label(): string
    {
        return match ($this) {
            self::Overpayment => 'Overpayment',
            self::AppliedToBill => 'Used for a bill',
            self::Refund => 'Refund',
            self::Adjustment => 'Adjustment',
        };
    }
}
