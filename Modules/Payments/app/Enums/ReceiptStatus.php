<?php

declare(strict_types=1);

namespace Modules\Payments\Enums;

use App\Enums\Concerns\HasValues;

/**
 * Where a receipt stands (P03-10b; worked out from `receipts`, not
 * stored): valid, valid with a void waiting for its second approver, or
 * voided (its VOID copy printed, the verify page says "voided").
 */
enum ReceiptStatus: string
{
    use HasValues;

    case Valid = 'valid';
    case VoidRequested = 'void_requested';
    case Voided = 'voided';

    public function label(): string
    {
        return match ($this) {
            self::Valid => 'Valid',
            self::VoidRequested => 'Void requested',
            self::Voided => 'Voided',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Valid => 'green',
            self::VoidRequested => 'amber',
            self::Voided => 'zinc',
        };
    }
}
