<?php

declare(strict_types=1);

namespace Modules\Payments\Contracts;

/**
 * Why money counts toward (or against) what was collected for a bill
 * (OwnerCollections, P08-05): a confirmed payment's share of the bill
 * (counted when the payment was confirmed), tenant credit used for the
 * bill (counted when it was used), or a voided receipt taking its
 * payment's share back (counted, negative, when the receipt was voided).
 */
enum CollectedKind: string
{
    case Payment = 'payment';
    case Credit = 'credit';
    case ReceiptVoided = 'receipt_voided';

    public function label(): string
    {
        return match ($this) {
            self::Payment => 'Payment confirmed',
            self::Credit => 'Tenant credit used',
            self::ReceiptVoided => 'Receipt voided',
        };
    }
}
