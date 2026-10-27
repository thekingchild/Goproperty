<?php

declare(strict_types=1);

namespace Modules\Billing\Enums;

use App\Enums\Concerns\HasValues;
use App\Support\Money;

/**
 * A bill's state (`bills.status`, DATABASE_SCHEMA.md § 4; the invoice
 * badges of BRANDING_DOCUMENTS.md § 2: UNPAID, PARTLY PAID, PAID, OVERDUE,
 * VOID). The values are App\Documents\Invoice\InvoiceStatus's, so an
 * invoice is printed with `InvoiceStatus::from($bill->status->value)`.
 *
 * Except `void`, the status follows from the bill's money and dates
 * (for()): paid in full → paid; otherwise, after the due date (Lagos) →
 * overdue (ARCHITECTURE.md § 6.4: a partly paid bill past its due date
 * "stays overdue"); otherwise partially_paid when something was paid,
 * else unpaid. The bill ledger works it out again whenever money is
 * applied or released; bills:flag-overdue moves bills past their due date
 * every day. `void` is final: a voided bill accepts nothing afterwards.
 */
enum BillStatus: string
{
    use HasValues;

    case Unpaid = 'unpaid';
    case PartiallyPaid = 'partially_paid';
    case Paid = 'paid';
    case Overdue = 'overdue';
    case Void = 'void';

    /**
     * The status of a bill that is not void, from its amount, what has been
     * paid and its due date, on the given day (dates as Y-m-d).
     */
    public static function for(Money $amount, Money $paid, string $dueDate, string $today): self
    {
        if ($paid->greaterThanOrEqual($amount)) {
            return self::Paid;
        }

        if ($dueDate < $today) {
            return self::Overdue;
        }

        return $paid->isPositive() ? self::PartiallyPaid : self::Unpaid;
    }

    public function label(): string
    {
        return match ($this) {
            self::Unpaid => 'Unpaid',
            self::PartiallyPaid => 'Partly paid',
            self::Paid => 'Paid',
            self::Overdue => 'Overdue',
            self::Void => 'Void',
        };
    }

    /** Flux badge colour. */
    public function color(): string
    {
        return match ($this) {
            self::Unpaid => 'amber',
            self::PartiallyPaid => 'blue',
            self::Paid => 'green',
            self::Overdue => 'red',
            self::Void => 'zinc',
        };
    }

    /** Whether the bill still asks for money (payments may be applied to it). */
    public function isOpen(): bool
    {
        return in_array($this, [self::Unpaid, self::PartiallyPaid, self::Overdue], true);
    }

    /**
     * The statuses bills:flag-overdue moves to overdue once the due date has passed.
     *
     * @return list<self>
     */
    public static function flaggable(): array
    {
        return [self::Unpaid, self::PartiallyPaid];
    }
}
