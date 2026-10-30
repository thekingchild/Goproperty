<?php

declare(strict_types=1);

namespace Modules\SaasBilling\Enums;

use App\Enums\Concerns\HasValues;

/** `platform_invoices.status` (DATABASE_SCHEMA.md § 3): open → paid | void. */
enum InvoiceStatus: string
{
    use HasValues;

    case Open = 'open';
    case Paid = 'paid';
    case Void = 'void';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Waiting for payment',
            self::Paid => 'Paid',
            self::Void => 'Not paid',
        };
    }
}
