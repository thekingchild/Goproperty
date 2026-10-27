<?php

declare(strict_types=1);

namespace Modules\Payments\Lists;

use Modules\Payments\Models\Payment;

/** A payment with its tenant's name (read through the Tenants contract a page or chunk at a time: PaymentRows). */
final readonly class PaymentRow
{
    public function __construct(
        public Payment $payment,
        public string $tenantName,
    ) {}
}
