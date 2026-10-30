<?php

declare(strict_types=1);

namespace Modules\SaasBilling\Contracts;

use App\Support\Money;

/** One line of a GoProperty invoice as shown to the customer (P06-02). */
final readonly class InvoiceLine
{
    public function __construct(
        public string $label,
        public Money $amount,
    ) {}
}
