<?php

declare(strict_types=1);

namespace Modules\Payments\Contracts;

use App\Support\Money;
use Carbon\CarbonImmutable;

/**
 * One amount paid on one bill as the owner portal shows it (OwnerPayments,
 * P03-16): plain values, no model. `kind` is `payment` (a confirmed
 * payment's part of the bill: `paidOn` the day the tenant paid, `at` when
 * it was confirmed, `method` a PaymentMethod value with its label, and its
 * receipt) or `credit` (tenant credit used for the bill: `at` when it was
 * used, no method or receipt). `refunded` marks a payment refunded since;
 * `receiptVoided` a payment whose receipt was voided. Never a reference or
 * bank details.
 */
final readonly class OwnerPayment
{
    public const string PAYMENT = 'payment';

    public const string CREDIT = 'credit';

    public function __construct(
        public string $kind,
        public int $billId,
        public Money $amount,
        public CarbonImmutable $at,
        public ?CarbonImmutable $paidOn,
        public ?string $method,
        public string $methodLabel,
        public ?int $receiptId,
        public ?string $receiptNumber,
        public bool $receiptVoided,
        public bool $refunded,
    ) {}
}
