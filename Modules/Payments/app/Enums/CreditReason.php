<?php

declare(strict_types=1);

namespace Modules\Payments\Enums;

use App\Enums\Concerns\HasValues;

/**
 * Why a tenant credit entry was written (`tenant_credit_entries.reason`,
 * DATABASE_SCHEMA.md § 4): + overpayment (a confirmed payment beyond the
 * tenant's open bills), − applied_to_bill (taken for a newly issued bill),
 * + opening_balance (credit brought over by the Opening balances import
 * sheet, P03-13b), − import_rolled_back (that credit taken back by the
 * import's rollback: entries are never deleted); at a management handover
 * (P03-14, P03 decision 11) − refund (the landlord refunded the moving
 * tenant's credit) or − handed_over (passed to the agency outside
 * GoProperty) on the landlord's books, and + brought_over (that credit
 * recorded by the agency once it arrived). `adjustment` is reserved for
 * later work (reversals).
 */
enum CreditReason: string
{
    use HasValues;

    case Overpayment = 'overpayment';
    case AppliedToBill = 'applied_to_bill';
    case Refund = 'refund';
    case Adjustment = 'adjustment';
    case OpeningBalance = 'opening_balance';
    case ImportRolledBack = 'import_rolled_back';
    case HandedOver = 'handed_over';
    case BroughtOver = 'brought_over';

    public function label(): string
    {
        return match ($this) {
            self::Overpayment => 'Overpayment',
            self::AppliedToBill => 'Used for a bill',
            self::Refund => 'Refund',
            self::Adjustment => 'Adjustment',
            self::OpeningBalance => 'Opening credit (imported)',
            self::ImportRolledBack => 'Import rolled back',
            self::HandedOver => 'Passed to the new manager',
            self::BroughtOver => 'Brought over from the previous manager',
        };
    }
}
