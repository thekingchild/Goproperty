<?php

declare(strict_types=1);

namespace Modules\OwnerStatements\Statements;

use Modules\OwnerStatements\Enums\SettlementStatus;
use Modules\OwnerStatements\Models\OwnerStatementSettlement;

/**
 * One owner's month as the pages show it (P08-05): the statement (the
 * snapshot when settled, else worked out now) and the agency's mark.
 */
final readonly class MonthStatement
{
    public function __construct(
        public OwnerStatement $statement,
        public ?OwnerStatementSettlement $settlement,
    ) {}

    public function month(): StatementMonth
    {
        return $this->statement->month;
    }

    public function isSettled(): bool
    {
        return $this->settlement?->isSettled() === true;
    }

    /** Settled, or not yet: "In progress" while the month runs, else "Unsettled". */
    public function statusLabel(): string
    {
        return match (true) {
            $this->isSettled() => SettlementStatus::Settled->label(),
            ! $this->month()->hasEnded() => 'In progress',
            default => SettlementStatus::Unsettled->label(),
        };
    }

    public function statusColor(): string
    {
        return match (true) {
            $this->isSettled() => SettlementStatus::Settled->color(),
            ! $this->month()->hasEnded() => 'zinc',
            default => SettlementStatus::Unsettled->color(),
        };
    }

    /** "settled", "in_progress" or "unsettled" (for tests and data attributes). */
    public function status(): string
    {
        return match (true) {
            $this->isSettled() => 'settled',
            ! $this->month()->hasEnded() => 'in_progress',
            default => 'unsettled',
        };
    }
}
