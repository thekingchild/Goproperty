<?php

declare(strict_types=1);

namespace Modules\Leasing\Contracts;

use App\Support\Money;
use DateTimeInterface;

/**
 * The rent the organization's leases will bill (P06-09, the Transaction
 * Tier forecast; ONBOARDING_COMPLIANCE.md § 5.1): never the `leases` table
 * itself. Counts the rent of every period of an active or renewed lease
 * that starts on or after $from and before $to, and not after the lease's
 * end date. Drafts and other charges are not counted. Answers for the
 * current organization only (OrganizationContext); no permission check.
 * Bound in LeasingServiceProvider.
 */
interface RentForecast
{
    public function expectedBetween(DateTimeInterface $from, DateTimeInterface $to): Money;
}
