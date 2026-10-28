<?php

declare(strict_types=1);

namespace Modules\Tickets\Tickets;

use App\Models\User;
use Carbon\CarbonImmutable;
use Modules\Tickets\Enums\TicketStatus;
use Modules\Tickets\Models\Ticket;
use Modules\Tickets\Models\TicketStatusChange;

/**
 * A ticket's status history as the ticket pages show it, oldest first:
 * each move with who made it (a member's or the tenant's name, or
 * "Closed automatically") and its note; on the tenant's own page their
 * moves say "You". Names from the users table (Core), one lookup a ticket.
 */
final class TicketHistory
{
    /** @return list<array{from: TicketStatus|null, to: TicketStatus, by: string, note: string|null, at: CarbonImmutable}> */
    public function of(Ticket $ticket, ?int $you = null): array
    {
        $changes = TicketStatusChange::query()->where('ticket_id', $ticket->id)->orderBy('changed_at')->orderBy('id')->get();
        $ids = $changes->pluck('changed_by_user_id')->filter()->unique()->values()->all();
        $names = $ids === [] ? [] : User::query()->whereKey($ids)->pluck('name', 'id')->all();

        return array_values($changes->map(static fn (TicketStatusChange $change): array => [
            'from' => $change->from_status,
            'to' => $change->to_status,
            'by' => match (true) {
                $change->changed_by_user_id === null => __('Closed automatically'),
                $you !== null && $change->changed_by_user_id === $you => __('You'),
                default => (string) ($names[$change->changed_by_user_id] ?? __('Former member')),
            },
            'note' => $change->note,
            'at' => $change->changed_at,
        ])->all());
    }
}
