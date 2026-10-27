<?php

declare(strict_types=1);

namespace Modules\Billing\Pay;

use App\Documents\DocumentBranding;
use App\Documents\Invoice\BankTransfer;
use App\Support\Money;
use Modules\Billing\Enums\BillStatus;

/**
 * What the public pay page shows about one bill (Pay\PayPages): the
 * agency's branding as on the invoice, the bill's number, dates, lines and
 * money, the building and unit it is for, the organization's bank account
 * and the online payment options. Nothing about the tenant: anyone holding
 * the payment reference opens this page (BRANDING_DOCUMENTS.md § 1 "Pay
 * page"). Plain values, ready for the view.
 */
final readonly class PayPage
{
    /**
     * @param  list<array{label: string, note: ?string, period: ?string, amount: string}>  $lines
     * @param  list<string>  $options  HTML of each payment option that offers the bill
     */
    public function __construct(
        public DocumentBranding $brand,
        public string $number,
        public string $reference,
        public BillStatus $status,
        public string $issuedOn,
        public string $dueOn,
        public ?string $period,
        public ?string $property,
        public array $lines,
        public Money $amount,
        public Money $paid,
        public Money $outstanding,
        public ?BankTransfer $bank,
        public array $options,
        public ?string $voidedOn,
    ) {}

    /** Whether the page asks for money (open, with something outstanding). */
    public function asksForMoney(): bool
    {
        return $this->status->isOpen() && $this->outstanding->isPositive();
    }

    public function isVoid(): bool
    {
        return $this->status === BillStatus::Void;
    }

    public function isPaid(): bool
    {
        return ! $this->isVoid() && ! $this->asksForMoney();
    }
}
