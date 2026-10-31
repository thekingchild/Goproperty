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
 * no proration (§ 1.4), so the handed-over units are first billed on the
 * receiving organization's next billing day (`from`), and `before->next` /
 * `after->next` are the invoice that day brings without and with them
 * (P06-06: the same amounts the metering run bills). `cheaper` is a plan
 * that would cost less for the units after, to suggest.
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
