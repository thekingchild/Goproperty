<?php

declare(strict_types=1);

namespace Modules\Payments\Enums;

use App\Enums\Concerns\HasValues;

/**
 * Where a proof of payment stands (P03-09; worked out, not stored): its
 * review is its payment's status (waiting, confirmed = accepted, rejected),
 * and a removed proof is archived (kept, with its file: evidence, AGENT.md
 * § 3 rule 7).
 */
enum ProofStatus: string
{
    use HasValues;

    case Waiting = 'waiting';
    case Accepted = 'accepted';
    case Rejected = 'rejected';
    case Archived = 'archived';

    public function label(): string
    {
        return match ($this) {
            self::Waiting => 'Waiting for review',
            self::Accepted => 'Accepted',
            self::Rejected => 'Rejected',
            self::Archived => 'Archived',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Waiting => 'amber',
            self::Accepted => 'green',
            self::Rejected => 'red',
            self::Archived => 'zinc',
        };
    }
}
