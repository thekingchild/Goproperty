<?php

declare(strict_types=1);

namespace App\Privacy;

use App\Enums\Concerns\HasValues;

/** `data_requests.kind`. */
enum DataRequestKind: string
{
    use HasValues;

    case Export = 'export';
    case Erasure = 'erasure';

    public function label(): string
    {
        return match ($this) {
            self::Export => 'Export',
            self::Erasure => 'Erasure',
        };
    }
}
