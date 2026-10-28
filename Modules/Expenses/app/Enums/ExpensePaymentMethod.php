<?php

declare(strict_types=1);

namespace Modules\Expenses\Enums;

use App\Enums\Concerns\HasValues;

/** How the organization paid an expense (`expenses.payment_method`). */
enum ExpensePaymentMethod: string
{
    use HasValues;

    case BankTransfer = 'bank_transfer';
    case Cash = 'cash';
    case Pos = 'pos';
    case Cheque = 'cheque';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::BankTransfer => 'Bank transfer',
            self::Cash => 'Cash',
            self::Pos => 'Card (POS)',
            self::Cheque => 'Cheque',
            self::Other => 'Other',
        };
    }
}
