<?php

declare(strict_types=1);

namespace Modules\Billing\Contracts;

use App\Support\Money;
use Carbon\CarbonImmutable;

/**
 * A bill that still asks for money, as a payment option sees it on the pay
 * page and the invoice (PaymentOptions): plain values, no model, no
 * tenant details. `outstanding` is what is still owed now.
 */
final readonly class PayableBill
{
    public function __construct(
        public int $id,
        public int $organizationId,
        public int $tenantProfileId,
        public string $number,
        public string $paymentReference,
        public Money $amount,
        public Money $outstanding,
        public CarbonImmutable $dueDate,
    ) {}
}
