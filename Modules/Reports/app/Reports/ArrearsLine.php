<?php

declare(strict_types=1);

namespace Modules\Reports\Reports;

use Modules\Billing\Contracts\ReportBill;
use Modules\Reports\Enums\AgingBucket;

/** A bill in arrears (P08-01): owed on or before today, `days` past its due date. */
final readonly class ArrearsLine
{
    public function __construct(
        public ReportBill $bill,
        public int $days,
        public AgingBucket $bucket,
        public string $buildingName,
    ) {}
}
