<?php

declare(strict_types=1);

namespace Modules\Properties\Enums;

use App\Enums\Concerns\HasValues;

/**
 * `properties.status` (DATABASE_SCHEMA.md § 3). An archived building is
 * kept with its history, read-only, and its units stop counting towards
 * the GoProperty bill (BILLING_PAYMENTS.md § 1.4). Archiving is never a
 * delete (AGENT.md § 3 rule 7); restoring makes it active again.
 */
enum PropertyStatus: string
{
    use HasValues;

    case Active = 'active';
    case Archived = 'archived';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Archived => 'Archived',
        };
    }
}
