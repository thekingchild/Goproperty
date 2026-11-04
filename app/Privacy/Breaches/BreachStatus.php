<?php

declare(strict_types=1);

namespace App\Privacy\Breaches;

use App\Enums\Concerns\HasValues;

/** `data_breaches.status` (docs/runbooks/breach-response.md). */
enum BreachStatus: string
{
    use HasValues;

    case Open = 'open';
    case Contained = 'contained';
    case Closed = 'closed';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Open',
            self::Contained => 'Contained',
            self::Closed => 'Closed',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Open => 'danger',
            self::Contained => 'warning',
            self::Closed => 'gray',
        };
    }
}
