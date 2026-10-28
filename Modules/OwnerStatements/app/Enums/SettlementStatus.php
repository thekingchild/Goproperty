<?php

declare(strict_types=1);

namespace Modules\OwnerStatements\Enums;

use App\Enums\Concerns\HasValues;

/**
 * The agency's mark on one owner's month (`owner_statement_settlements.status`,
 * P08-05): settled (paid to the owner outside GoProperty, with the date and
 * a reference; the figures kept as they were) or unsettled again (with a
 * reason). A month with no row is unsettled too. Changed only by
 * Statements\Settlements.
 */
enum SettlementStatus: string
{
    use HasValues;

    case Settled = 'settled';
    case Unsettled = 'unsettled';

    public function label(): string
    {
        return match ($this) {
            self::Settled => 'Settled',
            self::Unsettled => 'Unsettled',
        };
    }

    /** Flux badge colour. */
    public function color(): string
    {
        return match ($this) {
            self::Settled => 'green',
            self::Unsettled => 'amber',
        };
    }
}
