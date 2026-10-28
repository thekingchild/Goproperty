<?php

declare(strict_types=1);

namespace Modules\Tickets\Enums;

use App\Enums\Concerns\HasValues;

/**
 * Who wrote a message of a ticket's thread (`ticket_messages.author_kind`):
 * the ticket's tenant (portal), a member of the agency (workspace), or the
 * building's owner (`/owner`, only while no agent is assigned).
 */
enum MessageAuthor: string
{
    use HasValues;

    case Tenant = 'tenant';
    case Staff = 'staff';
    case Owner = 'owner';

    public function label(): string
    {
        return match ($this) {
            self::Tenant => 'Tenant',
            self::Staff => 'Agency',
            self::Owner => 'Owner',
        };
    }
}
