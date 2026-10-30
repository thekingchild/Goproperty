<?php

declare(strict_types=1);

namespace Modules\SaasBilling\Contracts;

use App\Support\Money;
use Carbon\CarbonImmutable;

/** A bundle bought (or being paid for), as the SMS credits page lists it (P05-03). */
final readonly class SmsCreditPurchase
{
    public function __construct(
        public string $invoiceNumber,
        public int $credits,
        public Money $total,
        /** paid, pending, failed, held */
        public string $status,
        public string $statusLabel,
        public CarbonImmutable $startedAt,
        public ?CarbonImmutable $paidAt,
    ) {}
}
