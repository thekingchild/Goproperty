<?php

declare(strict_types=1);

namespace Modules\Tickets\Lists;

use App\Models\User;
use Modules\Properties\Contracts\PropertyDirectory;
use Modules\Properties\Contracts\UnitDirectory;
use Modules\Tenants\Contracts\TenantDirectory;
use Modules\Tickets\Models\Ticket;

/**
 * The unit, building, tenant and member names of a batch of tickets, read
 * through the Properties and Tenants contracts and the users table (Core)
 * with one lookup each per batch (no query per ticket, ARCHITECTURE.md
 * § 13), never by joining another module's tables (§ 8 rule 3). Names
 * only: no contact details.
 */
final readonly class TicketParties
{
    public function __construct(
        private UnitDirectory $units,
        private PropertyDirectory $buildings,
        private TenantDirectory $tenants,
    ) {}

    /**
     * @param  iterable<Ticket>  $tickets
     * @return list<TicketRow>
     */
    public function rows(iterable $tickets): array
    {
        $tickets = is_array($tickets) ? array_values($tickets) : iterator_to_array($tickets, false);

        if ($tickets === []) {
            return [];
        }

        $ids = static fn (callable $pick): array => array_values(array_unique(array_filter(array_map($pick, $tickets), static fn (?int $id): bool => $id !== null)));
        $units = $this->units->findMany($ids(static fn (Ticket $ticket): int => $ticket->unit_id));
        $buildings = $this->buildings->findMany($ids(static fn (Ticket $ticket): int => $ticket->property_id));
        $tenants = $this->tenants->findMany($ids(static fn (Ticket $ticket): ?int => $ticket->tenant_profile_id));
        $userIds = [...$ids(static fn (Ticket $ticket): ?int => $ticket->assigned_to_user_id), ...$ids(static fn (Ticket $ticket): ?int => $ticket->raised_by_user_id)];
        $users = $userIds === [] ? [] : User::query()->whereKey(array_values(array_unique($userIds)))->pluck('name', 'id')->all();

        return array_map(static fn (Ticket $ticket): TicketRow => new TicketRow(
            ticket: $ticket,
            unitLabel: isset($units[$ticket->unit_id]) ? $units[$ticket->unit_id]->label : __('Archived unit'),
            buildingName: isset($buildings[$ticket->property_id]) ? $buildings[$ticket->property_id]->name : __('Removed building'),
            tenantName: $ticket->tenant_profile_id === null ? null : ($tenants[$ticket->tenant_profile_id]->name ?? (string) __('Unknown tenant')),
            assigneeName: $ticket->assigned_to_user_id === null ? null : (string) ($users[$ticket->assigned_to_user_id] ?? __('Former member')),
            raisedByName: $ticket->raised_by_user_id === null ? null : (string) ($users[$ticket->raised_by_user_id] ?? __('Former member')),
        ), $tickets);
    }
}
