<?php

declare(strict_types=1);

namespace Modules\Leasing\Import;

use App\Support\Money;
use Carbon\CarbonImmutable;
use Modules\Leasing\Contracts\BillingCycle;

/**
 * One Leases row as LeaseService::importOngoing() takes it (P03-13a):
 * checked by LeasesImportTarget, amounts in kobo through Money, dates as
 * the file gave them (`end` is the file's; a month-to-month lease gets its
 * own end date from OngoingLeaseDates). `contractNumber` null = number it
 * (or keep the one on file); `notes` null = keep what is on file.
 */
final readonly class ImportedLease
{
    public function __construct(
        public int $tenantProfileId,
        public int $unitId,
        public ImportedLeaseStatus $status,
        public BillingCycle $cycle,
        public CarbonImmutable $start,
        public CarbonImmutable $end,
        public Money $rent,
        public Money $deposit,
        public int $dueDayOffset,
        public ?CarbonImmutable $paidUntil,
        public bool $autoRenew,
        public ?string $contractNumber,
        public ?string $externalRef,
        public ?string $notes,
    ) {}
}
