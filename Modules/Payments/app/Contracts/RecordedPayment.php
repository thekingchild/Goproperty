<?php

declare(strict_types=1);

namespace Modules\Payments\Contracts;

use App\Support\Money;

/**
 * What Contracts\PaymentRecorder did: the payment (its id and status:
 * pending_confirmation, confirmed or rejected), whether this call created
 * it (false: the idempotency key was used before, and the earlier payment
 * is returned as it is, whatever this draft said), and once confirmed how
 * it was allocated (bill id => amount) and what went to tenant credit.
 */
final readonly class RecordedPayment
{
    /**
     * @param  array<int, Money>  $allocations  bill id => amount
     */
    public function __construct(
        public int $paymentId,
        public string $status,
        public bool $created,
        public Money $amount,
        public array $allocations,
        public Money $credit,
    ) {}

    public function isConfirmed(): bool
    {
        return $this->status === 'confirmed';
    }
}
