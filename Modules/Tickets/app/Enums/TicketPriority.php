<?php

declare(strict_types=1);

namespace Modules\Tickets\Enums;

use App\Enums\Concerns\HasValues;

/** How soon a ticket needs attention (`tickets.priority`, DATABASE_SCHEMA.md § 5). */
enum TicketPriority: string
{
    use HasValues;

    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';
    case Urgent = 'urgent';

    public function label(): string
    {
        return match ($this) {
            self::Low => 'Low',
            self::Medium => 'Medium',
            self::High => 'High',
            self::Urgent => 'Urgent',
        };
    }

    /** How a tenant is asked to choose it. */
    public function hint(): string
    {
        return match ($this) {
            self::Low => 'When you can: it does not get in the way',
            self::Medium => 'Soon: it is a nuisance',
            self::High => 'In a day or two: part of the home cannot be used',
            self::Urgent => 'Now: danger, flooding, no water or power, or the home is not secure',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Low => 'zinc',
            self::Medium => 'sky',
            self::High => 'amber',
            self::Urgent => 'red',
        };
    }
}
