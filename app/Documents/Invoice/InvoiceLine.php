<?php

declare(strict_types=1);

namespace App\Documents\Invoice;

use App\Documents\Parts\DocumentPeriod;
use App\Support\Money;

/**
 * One line of an invoice (BRANDING_DOCUMENTS.md § 2 item 4): description,
 * a short note, the period (a DocumentPeriod, a word such as "Once", or the
 * invoice's own period when null) and the amount.
 */
final readonly class InvoiceLine
{
    public function __construct(
        public string $description,
        public Money $amount,
        public ?string $note = null,
        public DocumentPeriod|string|null $period = null,
    ) {}

    public function periodLabel(?DocumentPeriod $default): string
    {
        $period = $this->period ?? $default;

        return $period instanceof DocumentPeriod ? $period->label() : (string) $period;
    }
}
