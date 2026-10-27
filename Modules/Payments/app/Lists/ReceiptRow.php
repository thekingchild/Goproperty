<?php

declare(strict_types=1);

namespace Modules\Payments\Lists;

use Modules\Payments\Models\Receipt;

/** A receipt with the names the list shows (read a page or chunk at a time: ReceiptRows). */
final readonly class ReceiptRow
{
    public function __construct(
        public Receipt $receipt,
        public string $tenantName,
        public string $method,
        public ?string $requestedBy,
        public ?string $approvedBy,
    ) {}
}
