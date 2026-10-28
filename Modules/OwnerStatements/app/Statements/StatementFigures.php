<?php

declare(strict_types=1);

namespace Modules\OwnerStatements\Statements;

use App\Support\Money;

/**
 * The money of a statement, for one building or the whole month (P08-05;
 * BILLING_PAYMENTS.md § 7.9): rent and utilities collected, the agency's
 * commission, the expenses charged to the owner and what that leaves due
 * to the owner:
 *
 *   net due = rent − commission + utilities (only when they pass to the
 *             owner) − charged expenses
 *
 * Commission is the agreed share of rent collected, in basis points,
 * rounded to the kobo (halves away from zero); none while it is not set.
 * Any of them may be negative (a receipt voided in the month takes its
 * money back; more expenses than rent leaves the owner owing).
 */
final readonly class StatementFigures
{
    public function __construct(
        public Money $rent,
        public Money $utilities,
        public Money $commission,
        public Money $expenses,
        public Money $netDue,
        public bool $utilitiesCounted,
    ) {}

    public static function work(Money $rent, Money $utilities, Money $expenses, ?int $commissionBps, bool $utilitiesToOwner): self
    {
        $commission = $commissionBps === null ? Money::zero() : $rent->percentage($commissionBps);
        $net = $rent->minus($commission)->minus($expenses);

        return new self($rent, $utilities, $commission, $expenses, $utilitiesToOwner ? $net->plus($utilities) : $net, $utilitiesToOwner);
    }

    /** @param  list<self>  $parts */
    public static function sum(array $parts, bool $utilitiesToOwner): self
    {
        return new self(
            Money::sum(array_map(static fn (self $part): Money => $part->rent, $parts)),
            Money::sum(array_map(static fn (self $part): Money => $part->utilities, $parts)),
            Money::sum(array_map(static fn (self $part): Money => $part->commission, $parts)),
            Money::sum(array_map(static fn (self $part): Money => $part->expenses, $parts)),
            Money::sum(array_map(static fn (self $part): Money => $part->netDue, $parts)),
            $utilitiesToOwner,
        );
    }

    /** @return array{rent: int, utilities: int, commission: int, expenses: int, net_due: int, utilities_counted: bool} */
    public function toArray(): array
    {
        return [
            'rent' => $this->rent->kobo,
            'utilities' => $this->utilities->kobo,
            'commission' => $this->commission->kobo,
            'expenses' => $this->expenses->kobo,
            'net_due' => $this->netDue->kobo,
            'utilities_counted' => $this->utilitiesCounted,
        ];
    }

    /** @param  array<string, mixed>  $data */
    public static function fromArray(array $data): self
    {
        return new self(
            Snapshot::money($data, 'rent'),
            Snapshot::money($data, 'utilities'),
            Snapshot::money($data, 'commission'),
            Snapshot::money($data, 'expenses'),
            Snapshot::money($data, 'net_due'),
            (bool) ($data['utilities_counted'] ?? false),
        );
    }
}
