<?php

declare(strict_types=1);

namespace Modules\Billing\Enums;

use App\Enums\Concerns\HasValues;

/**
 * What a bill charges for (`bills.type`, DATABASE_SCHEMA.md § 4). Rent
 * bills are generated from leases (P03-04b), utility bills from utility
 * settings (P03-06b), opening balances come from an import (P03-13b).
 */
enum BillType: string
{
    use HasValues;

    case Rent = 'rent';
    case Utility = 'utility';
    case OpeningBalance = 'opening_balance';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Rent => 'Rent',
            self::Utility => 'Utility',
            self::OpeningBalance => 'Opening balance',
            self::Other => 'Other',
        };
    }
}
