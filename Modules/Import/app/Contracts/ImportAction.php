<?php

declare(strict_types=1);

namespace Modules\Import\Contracts;

/** What a row does to the records. */
enum ImportAction: string
{
    case Create = 'create';
    case Update = 'update';
    // An archived record matched by its ID or natural key comes back (P02 decision 3).
    case Restore = 'restore';
    case Skip = 'skip';

    public function label(): string
    {
        return match ($this) {
            self::Create => 'New',
            self::Update => 'Updated',
            self::Restore => 'Restored',
            self::Skip => 'Skipped',
        };
    }
}
