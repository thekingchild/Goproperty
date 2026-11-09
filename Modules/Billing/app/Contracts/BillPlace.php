<?php

declare(strict_types=1);

namespace Modules\Billing\Contracts;

/**
 * Where a bill belongs, for summing money by building and bill type
 * (Contracts\BillReports::placesOf, P09-03): `type` is a `bills.type`
 * value, `propertyId` null for a bill of no building.
 */
final readonly class BillPlace
{
    public function __construct(
        public int $id,
        public ?int $propertyId,
        public string $type,
    ) {}
}
