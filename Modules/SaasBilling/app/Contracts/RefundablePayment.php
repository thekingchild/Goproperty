<?php

declare(strict_types=1);

namespace Modules\SaasBilling\Contracts;

use App\Support\Money;

/**
 * What can still be refunded of a payment into GoProperty's account
 * (P06-07b): what was paid, what is refunded or being refunded, what
 * waits for approval, and what is left.
 */
final readonly class RefundablePayment
{
    public function __construct(
        public int $paymentId,
        public int $organizationId,
        public string $invoiceNumber,
        public string $method,
        public bool $refundableStatus,
        public Money $paid,
        public Money $refunded,
        public Money $awaitingApproval,
    ) {}

    public function remaining(): Money
    {
        $left = $this->paid->minus($this->refunded)->minus($this->awaitingApproval);

        return $left->isNegative() ? Money::zero() : $left;
    }

    public function canBeRefunded(): bool
    {
        return $this->refundableStatus && $this->remaining()->isPositive();
    }

    public function isBankTransfer(): bool
    {
        return $this->method === 'bank_transfer';
    }
}
