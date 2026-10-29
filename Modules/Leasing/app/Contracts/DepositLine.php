<?php

declare(strict_types=1);

namespace Modules\Leasing\Contracts;

use App\Support\Money;
use Carbon\CarbonImmutable;

/**
 * One entry of a lease's deposit ledger as the owner portal shows it
 * (OwnerLeases, P03-16; `lease_deposit_entries`): `type` is a
 * DepositEntryType value (held, carried_in, carried_out, refunded, kept)
 * with its label, `amount` signed (what is refunded, kept or carried out
 * is negative), `note` the reason a part was kept or the contract number a
 * deposit was carried from or to. Records only: GoProperty never holds the
 * money (AGENT.md § 3 rule 8).
 */
final readonly class DepositLine
{
    public function __construct(
        public string $type,
        public string $label,
        public Money $amount,
        public ?string $note,
        public CarbonImmutable $recordedAt,
    ) {}
}
