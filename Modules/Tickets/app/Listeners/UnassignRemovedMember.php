<?php

declare(strict_types=1);

namespace Modules\Tickets\Listeners;

use App\Members\MemberRemoved;
use Modules\Tickets\Tickets\TicketService;

/**
 * A member left the organization (App\Members\MemberRemoved, sent inside
 * the removal's transaction with the organization's context set): the
 * tickets waiting on them become unassigned in the same transaction, so
 * owners and administrators see them (TicketService::unassignMember).
 */
final readonly class UnassignRemovedMember
{
    public function __construct(private TicketService $tickets) {}

    public function handle(MemberRemoved $event): void
    {
        $this->tickets->unassignMember($event->userId, $event->removedByUserId);
    }
}
