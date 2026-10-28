<?php

declare(strict_types=1);

namespace Modules\Billing\Documents;

use App\Documents\Document;
use App\Documents\DocumentBranding;
use App\Documents\Invoice\InvoiceDocument;
use InvalidArgumentException;

/**
 * A utility bill as printed (P03-06b; BRANDING_DOCUMENTS.md § 4 "Utility
 * bill", § 5 "How it is built (P03-06b)"): the bill's invoice (Core's
 * InvoiceDocument: branding and parties as issued, number, dates, status,
 * totals, how to pay, verify box) with the utility it is for and, for a
 * metered utility, the readings table — previous, current, consumption,
 * rate and amount per reading. Printed in the organization's layout
 * (`billing::documents.utility.{modern,classic,compact}`) on the document
 * kit's partials and the invoice's sections, so it renders alike with
 * dompdf and Chrome. Built by Documents\Invoices for `utility` bills.
 */
final readonly class UtilityBillDocument implements Document
{
    /**
     * @param  string  $charging  "Metered, per kWh" or "Flat charge, monthly"
     * @param  list<UtilityReadingLine>  $readings  empty for a flat utility
     */
    public function __construct(
        public InvoiceDocument $invoice,
        public string $utility,
        public string $charging,
        public bool $metered,
        public string $unit = '',
        public array $readings = [],
    ) {
        if ($metered && $readings === []) {
            throw new InvalidArgumentException('A metered utility bill prints the readings it bills.');
        }
    }

    public function view(): string
    {
        return 'billing::documents.utility.'.$this->invoice->brand->layout()->value;
    }

    public function data(): array
    {
        return ['invoice' => $this->invoice, 'utilityBill' => $this];
    }

    public function brand(): DocumentBranding
    {
        return $this->invoice->brand;
    }

    public function fileName(): string
    {
        return $this->invoice->fileName();
    }
}
