<?php

declare(strict_types=1);

namespace Modules\Billing\Enums;

use App\Enums\Concerns\HasValues;

/**
 * What a bill charges for (`bills.type`, DATABASE_SCHEMA.md § 4). Rent
 * bills are generated from leases (P03-04b), utility bills from utility
 * settings (P03-06b), opening balances come from an import (P03-13b),
 * late fees from the LateFees module through Contracts\LateFeeBills
 * (PL-02, docs/plans/late-fees-2026-11-11.md).
 */
enum BillType: string
{
    use HasValues;

    case Rent = 'rent';
    case Utility = 'utility';
    case OpeningBalance = 'opening_balance';
    case Other = 'other';
    case LateFee = 'late_fee';

    public function label(): string
    {
        return match ($this) {
            self::Rent => 'Rent',
            self::Utility => 'Utility',
            self::OpeningBalance => 'Opening balance',
            self::Other => 'Other',
            self::LateFee => 'Late fee',
        };
    }
}
