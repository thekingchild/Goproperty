<?php

declare(strict_types=1);

namespace Modules\Payments\Contracts;

use App\Support\Money;

/**
 * Where a rent bill stands against the number of payments its lease allows
 * (P03-17; docs/plans/rent-part-payments-2026-10-31.md): the limit, the
 * confirmed payments that went to it (a payment that spilled over from
 * another bill counts; tenant credit applied automatically does not), the
 * payments still waiting for confirmation that were made for it, and what
 * the bill still owes beyond those waiting payments.
 */
final readonly class PartPaymentAllowance
{
    public function __construct(
        public int $billId,
        public int $limit,
        public int $made,
        public int $waiting,
        public Money $waitingAmount,
        public Money $outstanding,
    ) {}

    /** Payments made or waiting. */
    public function used(): int
    {
        return $this->made + $this->waiting;
    }

    /** How many more payments the bill takes (0 when none). */
    public function remaining(): int
    {
        return max(0, $this->limit - $this->used());
    }

    /** More confirmed payments than the limit (the agency confirmed money over it). */
    public function isOver(): bool
    {
        return $this->made > $this->limit;
    }

    /** What the next payment must at least be when it is the last one allowed: the bill's balance beyond the payments waiting. */
    public function lastPaymentAtLeast(): Money
    {
        return Money::max(Money::zero(), $this->outstanding->minus($this->waitingAmount));
    }

    /** For a tenant about to send a proof: the line, what this one must be when it is the last allowed, or that none is left. */
    public function forNextPayment(): string
    {
        return match ($this->remaining()) {
            0 => trans_choice('This bill is paid in one payment, which has been made or is waiting for the agency. Contact the agency if you paid again.|This bill has had all :limit payments it may be paid in, or they are waiting for the agency. Contact the agency if you paid again.', $this->limit, ['limit' => $this->limit]),
            1 => $this->describe().' '.__('This payment must be the full :amount.', ['amount' => $this->lastPaymentAtLeast()->format()]),
            default => $this->describe(),
        };
    }

    /** "You can pay this bill in up to 3 payments (1 made)", for tenants and members. */
    public function describe(): string
    {
        if ($this->limit === 1) {
            return __('This bill is paid in one payment.');
        }

        return trans_choice('You can pay this bill in up to :limit payments (:made made).|You can pay this bill in up to :limit payments (:made made).', $this->limit, ['limit' => $this->limit, 'made' => $this->made]);
    }
}
