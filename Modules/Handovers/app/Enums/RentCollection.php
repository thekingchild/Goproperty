<?php

declare(strict_types=1);

namespace Modules\Handovers\Enums;

use App\Enums\Concerns\HasValues;

/**
 * Who collects rent for handed-over buildings (BILLING_PAYMENTS.md § 7.5).
 * Either way GoProperty never touches the money (AGENT.md § 3 rule 8).
 * Only `agency` is offered: `owner` needs a collection account per
 * building, which does not exist yet (P03-07 built none).
 */
enum RentCollection: string
{
    use HasValues;

    /** Tenants pay the agency's own account; the agency pays the landlord, outside GoProperty. */
    case Agency = 'agency';

    /** Tenants pay the landlord's own account (not offered yet). */
    case Owner = 'owner';

    public function label(): string
    {
        return match ($this) {
            self::Agency => 'The agency collects rent',
            self::Owner => 'The landlord collects rent',
        };
    }

    public function isOffered(): bool
    {
        return $this === self::Agency;
    }
}
