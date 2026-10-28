<?php

declare(strict_types=1);

namespace Modules\Payments\Payments;

use App\Support\Money;
use Carbon\CarbonImmutable;
use Modules\Payments\Contracts\PaymentMethod;

/**
 * A payment from before GoProperty, as a Payment history import row gives
 * it (P03-13b; DATA_IMPORT.md § 1 row 8), checked by
 * Import\PaymentHistoryImportTarget. `issueReceipt`: the agency ticked
 * "Issue receipt" on the row (no receipt otherwise).
 */
final readonly class ImportedPayment
{
    public function __construct(
        public int $tenantProfileId,
        public Money $amount,
        public PaymentMethod $method,
        public CarbonImmutable $paidAt,
        public ?string $reference,
        public ?string $note,
        public ?string $externalRef,
        public bool $issueReceipt,
    ) {}
}
