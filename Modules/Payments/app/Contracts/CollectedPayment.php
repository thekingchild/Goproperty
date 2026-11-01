<?php

declare(strict_types=1);

namespace Modules\Payments\Contracts;

use App\Support\Money;
use Carbon\CarbonImmutable;

/**
 * One confirmed tenant payment as the Transaction Tier monitoring sees it
 * (CollectionVolumes, P06-09): plain values, no model.
 */
final readonly class CollectedPayment
{
    public function __construct(
        public int $id,
        public int $tenantProfileId,
        public Money $amount,
        public PaymentMethod $method,
        public PayerType $payerType,
        public PaymentSource $source,
        public CarbonImmutable $paidAt,
    ) {}
}
