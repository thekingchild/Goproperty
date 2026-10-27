<?php

declare(strict_types=1);

namespace App\Documents\Invoice;

use App\Enums\Concerns\HasValues;

/**
 * The status badge of an invoice (BRANDING_DOCUMENTS.md § 2 item 2:
 * UNPAID, PARTLY PAID, PAID, OVERDUE, VOID). The values are the bill
 * statuses of DATABASE_SCHEMA.md § 4 (`bills.status`), so Billing maps a
 * bill with `InvoiceStatus::from($bill->status)`; `draft` is for a bill
 * shown before it is issued (a preview), which the schema has no status for.
 *
 * Badge colours are fixed, not the agency's (a status must read the same on
 * every agency's invoices), each at least 4.5:1 text on its background.
 */
enum InvoiceStatus: string
{
    use HasValues;

    case Draft = 'draft';
    case Unpaid = 'unpaid';
    case PartlyPaid = 'partially_paid';
    case Paid = 'paid';
    case Overdue = 'overdue';
    case Void = 'void';

    /** As printed on the badge. */
    public function label(): string
    {
        return match ($this) {
            self::Draft => 'DRAFT',
            self::Unpaid => 'UNPAID',
            self::PartlyPaid => 'PARTLY PAID',
            self::Paid => 'PAID',
            self::Overdue => 'OVERDUE',
            self::Void => 'VOID',
        };
    }

    /** The badge's CSS class (resources/views/documents/partials/styles). */
    public function badgeClass(): string
    {
        return 'badge-'.str_replace('_', '-', $this->value);
    }

    /** @return array{background: string, text: string} */
    public function badgeColours(): array
    {
        return match ($this) {
            self::Draft => ['background' => '#e2e8f0', 'text' => '#334155'],
            self::Unpaid => ['background' => '#fef3c7', 'text' => '#92400e'],
            self::PartlyPaid => ['background' => '#dbeafe', 'text' => '#1e40af'],
            self::Paid => ['background' => '#dcfce7', 'text' => '#166534'],
            self::Overdue => ['background' => '#fee2e2', 'text' => '#991b1b'],
            self::Void => ['background' => '#1e293b', 'text' => '#ffffff'],
        };
    }

    /** Whether the invoice still asks for money (the pay panel and amount due). */
    public function isPayable(): bool
    {
        return in_array($this, [self::Unpaid, self::PartlyPaid, self::Overdue], true);
    }
}
