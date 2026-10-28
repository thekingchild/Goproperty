<?php

declare(strict_types=1);

namespace Modules\Expenses\Enums;

use App\Enums\Concerns\HasValues;

/**
 * What an expense was for (`expenses.category`, DATABASE_SCHEMA.md § 4:
 * the six categories the schema names). The hints give Nigerian examples so
 * a member files a diesel purchase or a tenement rate the same way every
 * time; reports group by category (P08).
 */
enum ExpenseCategory: string
{
    use HasValues;

    case Repairs = 'repairs';
    case Utilities = 'utilities';
    case Staff = 'staff';
    case Taxes = 'taxes';
    case AgencyOps = 'agency_ops';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Repairs => 'Repairs and maintenance',
            self::Utilities => 'Utilities',
            self::Staff => 'Staff',
            self::Taxes => 'Taxes, rates and levies',
            self::AgencyOps => 'Agency running costs',
            self::Other => 'Other',
        };
    }

    public function hint(): string
    {
        return match ($this) {
            self::Repairs => 'Plumbing, electrical work, painting, roofing, borehole and generator servicing',
            self::Utilities => 'Prepaid electricity, diesel for the generator, water, waste collection',
            self::Staff => 'Security guards, cleaners, facility managers, caretakers',
            self::Taxes => 'Tenement rate, Land Use Charge, ground rent, estate levies',
            self::AgencyOps => 'Office rent, transport, marketing, bank charges, software',
            self::Other => 'Anything else',
        };
    }
}
