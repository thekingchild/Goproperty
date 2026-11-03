<?php

declare(strict_types=1);

namespace Modules\Reports\Reports;

use App\Support\Money;
use Modules\Properties\Contracts\UnitCounts;

/**
 * One building's performance (P08-01): its units now, money billed and
 * collected and expenses in the range, what its tenants owe past due now,
 * and collected less expenses. `propertyId` null is the organization's own
 * money linked to no building.
 */
final readonly class BuildingLine
{
    public function __construct(
        public ?int $propertyId,
        public string $name,
        public bool $active,
        public UnitCounts $units,
        public FlowTotals $flows,
        public Money $arrears,
        public Money $expenses,
    ) {}

    public function net(): Money
    {
        return $this->flows->collectedTotal()->minus($this->expenses);
    }
}
