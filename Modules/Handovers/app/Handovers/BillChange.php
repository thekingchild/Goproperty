<?php

declare(strict_types=1);

namespace Modules\Handovers\Handovers;

use Carbon\CarbonImmutable;
use Modules\SaasBilling\Contracts\MonthlyBill;

/**
 * The receiving organization's GoProperty bill before and after the
 * handed-over units (BILLING_PAYMENTS.md § 7.3): "Your GoProperty bill
 * goes from {before}/month to {after}/month (before VAT) from your next
 * billing day, {date}." Units are counted on the billing day and there is
 * no proration (§ 1.4). `cheaper` is a plan that would cost less for the
 * units after, to suggest.
 */
final readonly class BillChange
{
    public function __construct(
        public MonthlyBill $before,
        public MonthlyBill $after,
        public int $units,
        public CarbonImmutable $from,
        public ?MonthlyBill $cheaper,
    ) {}
}
