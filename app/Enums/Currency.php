<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasValues;

/** Naira only at launch (BILLING_PAYMENTS.md); amounts are integer kobo. */
enum Currency: string
{
    use HasValues;

    case NGN = 'NGN';

    public function symbol(): string
    {
        return '₦';
    }

    /** Minor units per major unit (kobo per naira). */
    public function minorUnits(): int
    {
        return 100;
    }
}
