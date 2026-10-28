<?php

declare(strict_types=1);

namespace Modules\Tickets\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Support\Str;

/**
 * A ticket arrived with its building on a handover (P04-05;
 * BILLING_PAYMENTS.md § 7.4): sent by Tickets\TicketService::receiveMoved()
 * for the receiving organization's new ticket (never for a tenant's draft,
 * which nobody else sees), after the handover's transaction commits
 * (ShouldDispatchAfterCommit), so nothing is announced for a move that
 * rolled back. Listeners\TellTicketParties tells the tenant ("your repair
 * request is now handled by …") and the receiving organization's owners
 * and administrators, once each.
 *
 * Plain values only: the new ticket (`organizationId`, `ticketId`,
 * `number`) and where it came from as the ticket shows it
 * (`fromOrganization`, `fromNumber`). `key` (a ULID) names the
 * announcement, so a retried listener tells nobody twice.
 */
final readonly class TicketMovedIn implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public string $key;

    public function __construct(
        public int $organizationId,
        public int $ticketId,
        public string $number,
        public string $fromOrganization,
        public ?string $fromNumber,
        public ?int $tenantProfileId,
        ?string $key = null,
    ) {
        $this->key = $key ?? (string) Str::ulid();
    }
}
