<?php

declare(strict_types=1);

namespace Modules\SaasBilling\Contracts;

use App\Support\Money;
use Carbon\CarbonImmutable;

/**
 * The latest proof of a bank transfer an owner sent for a subscription
 * invoice (P06-04), as Settings → Subscription shows it: waiting for
 * GoProperty, confirmed, or not accepted with the reason.
 */
final readonly class BankTransferStatus
{
    public function __construct(
        /** pending, confirmed, rejected */
        public string $status,
        public string $statusLabel,
        public Money $amount,
        public ?CarbonImmutable $transferredOn,
        public ?CarbonImmutable $sentAt,
        public ?string $rejectionReason,
    ) {}

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }
}
