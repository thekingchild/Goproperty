<?php

declare(strict_types=1);

namespace Modules\Tickets\Notices;

/**
 * What a ticket notice says about the ticket, read once per announcement
 * by TicketNotifier::facts(): its number and title, the unit and building
 * (names only), the tenant's name, the agency's name as branded
 * (Branding::displayName) and who acted (a member's or tenant's name; null
 * for the system). Plain strings, safe to put in a queued notification.
 */
final readonly class TicketFacts
{
    public function __construct(
        public int $ticketId,
        public string $number,
        public string $title,
        public string $status,
        public string $priority,
        public string $unit,
        public string $building,
        public ?string $tenantName,
        public string $agency,
        public ?string $actorName,
        public int $reopenDays,
    ) {}

    /** "Flat 3B, Palm Court" */
    public function home(): string
    {
        return $this->unit.', '.$this->building;
    }
}
