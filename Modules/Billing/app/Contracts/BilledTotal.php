<?php

declare(strict_types=1);

namespace Modules\Billing\Contracts;

use App\Support\Money;

/**
 * What one building billed of one bill type in one period (a month of the
 * Reports page; Contracts\BillReports::billedTotals, P09-03): `type` is a
 * `bills.type` value, `month` the key of the period the bills were issued
 * in, `propertyId` null for bills of no building. Void bills are not in it.
 */
final readonly class BilledTotal
{
    public function __construct(
        public ?int $propertyId,
        public string $type,
        public string $month,
        public Money $amount,
        public int $count,
    ) {}
}
