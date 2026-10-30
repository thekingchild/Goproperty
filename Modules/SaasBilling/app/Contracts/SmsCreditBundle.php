<?php

declare(strict_types=1);

namespace Modules\SaasBilling\Contracts;

use App\Support\Money;

/** A bundle of SMS credits on offer: its credits, price before VAT, VAT and total (P05-03). */
final readonly class SmsCreditBundle
{
    public function __construct(
        public int $credits,
        public Money $price,
        public Money $vat,
        public Money $total,
    ) {}
}
