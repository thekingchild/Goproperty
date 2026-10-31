<?php

declare(strict_types=1);

namespace Modules\SaasBilling\Contracts;

use App\Enums\BillingCycle;
use App\Support\Money;
use Carbon\CarbonImmutable;

/**
 * The subscription invoice the next metering run will issue for a number
 * of units (P06-06; BILLING_PAYMENTS.md § 3, § 7.3): the day it is billed,
 * the period it covers and its amounts, worked out by the same rules as
 * metering (the plan's terms in effect on the period's first day, a part
 * month by days, a year at 10 months' price, VAT on the subtotal). The
 * handover's accept screen shows it before and after the handed-over units.
 */
final readonly class NextInvoice
{
    public function __construct(
        /** The Lagos day the metering run bills it (today when it is already due). */
        public CarbonImmutable $billingDay,
        public CarbonImmutable $periodStart,
        public CarbonImmutable $periodEnd,
        public BillingCycle $cycle,
        /** Yearly: the months paid for (10); monthly: 1. */
        public int $monthsBilled,
        /** A part month: the days charged, else null. */
        public ?int $daysBilled,
        public ?int $daysInMonth,
        /** Before VAT. */
        public Money $subtotal,
        public Money $vat,
        public Money $total,
        /** A platform-covered organization or payer: issued as paid, nothing to pay (§ 2). */
        public bool $covered,
    ) {}

    public function isPartMonth(): bool
    {
        return $this->daysBilled !== null;
    }

    /** "1–31 January 2027", "16 November 2026 – 15 November 2027". */
    public function period(): string
    {
        if ($this->periodStart->isSameMonth($this->periodEnd)) {
            return $this->periodStart->format('j').'–'.$this->periodEnd->format('j F Y');
        }

        return $this->periodStart->format('j F Y').' – '.$this->periodEnd->format('j F Y');
    }
}
