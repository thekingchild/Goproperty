<?php

declare(strict_types=1);

namespace Modules\SaasBilling\Contracts;

/**
 * GoProperty's own bank account for paying its invoices by transfer
 * (P06-04; BILLING_PAYMENTS.md § 5 step 1), set by `finance-admin` in the
 * console (Settings → Bank transfer details).
 */
final readonly class TransferAccount
{
    public function __construct(
        public string $bank,
        public string $accountName,
        public string $accountNumber,
    ) {}

    /** One line for an email: the bank, the number and the name. */
    public function describe(): string
    {
        return __(':bank, account :number (:name)', ['bank' => $this->bank, 'number' => $this->accountNumber, 'name' => $this->accountName]);
    }
}
