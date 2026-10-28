<?php

declare(strict_types=1);

namespace Modules\Tickets\Messages;

/**
 * Who reads a thread, which decides what TicketThread returns: staff (the
 * workspace) see internal notes; the tenant (portal) and the owner
 * (`/owner`) never do — the query itself leaves them out, so no page,
 * Livewire payload or file link built from it can carry one.
 */
enum ThreadViewer: string
{
    case Staff = 'staff';
    case Tenant = 'tenant';
    case Owner = 'owner';

    public function seesInternalNotes(): bool
    {
        return $this === self::Staff;
    }
}
