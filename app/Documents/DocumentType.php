<?php

declare(strict_types=1);

namespace App\Documents;

use App\Branding\Branding;
use App\Enums\Concerns\HasValues;

/**
 * The numbered documents an organization issues (ARCHITECTURE.md § 6.5,
 * BRANDING_DOCUMENTS.md § 1 "Document prefixes"), one number sequence each
 * per year in `document_counters`. Every type has its own prefix in the
 * organization's branding: invoices `INV-ADR`, receipts `RCT-ADR`, lease
 * contracts `LS-ADR`.
 *
 * Rent and utility bills are both invoices: they share the `bills` table,
 * whose `number` is unique per organization, so they share one sequence. A
 * new numbered document (owner statements, notices) adds a case here and a
 * prefix in Branding.
 *
 * Maintenance tickets (P04-01, docs/plans/P04-subtasks.md default 4) are
 * numbered records rather than documents: one sequence per organization
 * that never restarts (`yearly()` false: counted under year 0), with the
 * fixed prefix `TKT` and no year in the number (`TKT-000001`).
 */
enum DocumentType: string
{
    use HasValues;

    case Invoice = 'invoice';
    case Receipt = 'receipt';
    case LeaseContract = 'lease_contract';
    case Ticket = 'ticket';

    public function label(): string
    {
        return match ($this) {
            self::Invoice => 'Invoice',
            self::Receipt => 'Receipt',
            self::LeaseContract => 'Lease contract',
            self::Ticket => 'Maintenance ticket',
        };
    }

    /** This type's prefix in the organization's branding, e.g. "INV-ADR". */
    public function prefix(Branding $branding): string
    {
        return match ($this) {
            self::Invoice => $branding->invoicePrefix,
            self::Receipt => $branding->receiptPrefix,
            self::LeaseContract => $branding->leasePrefix,
            self::Ticket => 'TKT',
        };
    }

    /** Whether the sequence starts again at 1 each year (and the number carries the year). */
    public function yearly(): bool
    {
        return $this !== self::Ticket;
    }
}
