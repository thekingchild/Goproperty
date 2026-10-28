<?php

declare(strict_types=1);

namespace Modules\Tickets\Enums;

use App\Enums\Concerns\HasValues;

/** Who raised a ticket (`tickets.source`): the tenant in the portal, or a member on their behalf or for a unit with no tenant. */
enum TicketSource: string
{
    use HasValues;

    case Portal = 'portal';
    case Staff = 'staff';

    public function label(): string
    {
        return match ($this) {
            self::Portal => 'Tenant, in the portal',
            self::Staff => 'Staff',
        };
    }
}
