<?php

declare(strict_types=1);

namespace Modules\Payments\Contracts;

use App\Support\Money;
use Carbon\CarbonImmutable;

/**
 * Money collected for one bill at one moment (OwnerCollections, P08-05):
 * plain values, no model. `amount` is negative for a voided receipt.
 * `paymentId` is null for tenant credit; `receiptNumber` is the payment's
 * receipt, when it has one.
 */
final readonly class CollectedAmount
{
    public function __construct(
        public int $billId,
        public Money $amount,
        public CarbonImmutable $at,
        public CollectedKind $kind,
        public ?int $paymentId,
        public ?string $receiptNumber,
    ) {}
}
