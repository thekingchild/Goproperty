<?php

declare(strict_types=1);

namespace App\Documents\Receipt;

use App\Documents\Parts\DocumentDates;
use Carbon\CarbonImmutable;

/**
 * How the money came in (BRANDING_DOCUMENTS.md § 3 item 4): method, date
 * paid, the organization's account it was paid into (masked: only the last
 * four digits are kept), the bank narration or Paystack reference, and how
 * it was confirmed (statement match, Paystack, proof reviewed, cash).
 */
final readonly class ReceiptPayment
{
    public ?string $accountEnding;

    /**
     * @param  string  $method  "Bank transfer", "Paystack", "Cash"
     * @param  ?string  $accountNumber  the account paid into; only its last four digits are kept
     * @param  string  $referenceLabel  "Bank narration" or "Paystack reference"
     * @param  string  $confirmation  "Matched from bank statement", "Paystack", "Proof reviewed", "Cash"
     */
    public function __construct(
        public string $method,
        public CarbonImmutable $paidOn,
        public string $confirmation,
        public ?string $bank = null,
        ?string $accountNumber = null,
        public ?string $reference = null,
        public string $referenceLabel = 'Bank narration',
    ) {
        $digits = $accountNumber === null ? '' : (string) preg_replace('/\D+/', '', $accountNumber);
        $this->accountEnding = $digits === '' ? null : substr($digits, -4);
    }

    /** "GTBank ···6789", or null when no account applies (cash). */
    public function paidInto(): ?string
    {
        if ($this->bank === null && $this->accountEnding === null) {
            return null;
        }

        return trim(($this->bank ?? '').($this->accountEnding !== null ? ' ···'.$this->accountEnding : ''));
    }

    /** @return array<string, string> label => value, as printed */
    public function rows(): array
    {
        return array_filter([
            'Method' => $this->method,
            'Date paid' => DocumentDates::day($this->paidOn),
            'Paid into' => $this->paidInto(),
            $this->referenceLabel => $this->reference,
            'Confirmed' => $this->confirmation,
        ], static fn (?string $value): bool => $value !== null && $value !== '');
    }
}
