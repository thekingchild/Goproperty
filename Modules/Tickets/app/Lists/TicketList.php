<?php

declare(strict_types=1);

namespace Modules\Tickets\Lists;

use App\Exports\ExportColumn;
use App\Exports\ListExport;
use App\Exports\ListFilters;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Modules\Properties\Contracts\BuildingAccess;
use Modules\Tenants\Contracts\TenantDirectory;
use Modules\Tickets\Enums\TicketStatus;
use Modules\Tickets\Models\Ticket;

/**
 * The tickets list (/o/{slug}/tickets, Livewire\TicketIndex) and its
 * export (P02-07, AGENT.md § 5): the organization's sent tickets (never a
 * tenant's draft) in the member's buildings (an assigned agent: theirs
 * only, through Modules\Properties\Contracts\BuildingAccess), filtered by
 * status ("open": waiting for the organization) and building, with a
 * search over the number, the title and the tenant's name, phone or email
 * (Modules\Tenants\Contracts\TenantDirectory); newest sent first. The
 * inbox by status is P04-03's. Rows carry names only (TicketParties), no
 * contact details, so staff may export it while impersonating, audited.
 */
final class TicketList implements ListExport
{
    public const int CHUNK = 500;

    /** The status filter's value for every open ticket. */
    public const string OPEN = 'open';

    public function __construct(
        private readonly BuildingAccess $access,
        private readonly TenantDirectory $tenants,
        private readonly TicketParties $parties,
    ) {}

    public function key(): string
    {
        return 'tickets';
    }

    public function title(): string
    {
        return 'Maintenance tickets';
    }

    public function filters(array $input): array
    {
        return ListFilters::set([
            'search' => ListFilters::search($input['search'] ?? null),
            'status' => ListFilters::option($input['status'] ?? null, [self::OPEN, ...array_map(static fn (TicketStatus $status): string => $status->value, TicketStatus::sent())]),
            'building' => ListFilters::id($input['building'] ?? null),
        ]);
    }

    public function authorize(User $user, array $filters): void
    {
        Gate::forUser($user)->authorize('export', Ticket::class);
    }

    /**
     * The list's rows: organization scope, sent tickets, narrowing,
     * filters, search, newest sent first.
     *
     * @param  array<string, string|int|bool>  $filters  cleaned
     * @return Builder<Ticket>
     */
    public function query(User $user, array $filters): Builder
    {
        $search = is_string($filters['search'] ?? null) ? $filters['search'] : null;
        $status = (string) ($filters['status'] ?? '');
        $building = is_int($filters['building'] ?? null) ? $filters['building'] : null;
        $limited = $this->access->limitedTo($user);

        return Ticket::query()
            ->where('status', '!=', TicketStatus::Draft)
            ->when($limited !== null, fn (Builder $query) => $query->whereIn('property_id', $limited ?? []))
            ->when($status === self::OPEN, fn (Builder $query) => $query->whereIn('status', TicketStatus::open()))
            ->when(TicketStatus::tryFrom($status) !== null, fn (Builder $query) => $query->where('status', $status))
            ->when($building !== null, fn (Builder $query) => $query->where('property_id', $building))
            ->when($search !== null, function (Builder $query) use ($search): void {
                $tenantIds = $this->tenants->search((string) $search);

                $query->where(fn (Builder $query) => $query
                    ->where('number', 'like', ListFilters::like((string) $search))
                    ->orWhere('title', 'like', ListFilters::like((string) $search))
                    ->when($tenantIds !== [], fn (Builder $query) => $query->orWhereIn('tenant_profile_id', $tenantIds)));
            })
            ->orderByDesc('submitted_at')
            ->orderByDesc('id');
    }

    /** @return iterable<TicketRow> */
    public function rows(User $user, array $filters): iterable
    {
        foreach ($this->query($user, $filters)->lazy(self::CHUNK)->chunk(self::CHUNK) as $chunk) {
            yield from $this->parties->rows($chunk);
        }
    }

    public function count(User $user, array $filters): int
    {
        return $this->query($user, $filters)->toBase()->getCountForPagination();
    }

    public function columns(User $user): array
    {
        return [
            ExportColumn::make('Number', fn (TicketRow $row) => $row->ticket->number),
            ExportColumn::make('Status', fn (TicketRow $row) => $row->ticket->status),
            ExportColumn::make('Title', fn (TicketRow $row) => $row->ticket->title),
            ExportColumn::make('Category', fn (TicketRow $row) => $row->ticket->category),
            ExportColumn::make('Priority', fn (TicketRow $row) => $row->ticket->priority),
            ExportColumn::make('Building', fn (TicketRow $row) => $row->buildingName),
            ExportColumn::make('Unit', fn (TicketRow $row) => $row->unitLabel),
            ExportColumn::make('Tenant', fn (TicketRow $row) => $row->tenantName),
            ExportColumn::make('Raised by', fn (TicketRow $row) => $row->ticket->source),
            ExportColumn::make('Assigned to', fn (TicketRow $row) => $row->assigneeName),
            ExportColumn::make('Sent on', fn (TicketRow $row) => $row->ticket->submitted_at),
            ExportColumn::make('Resolved on', fn (TicketRow $row) => $row->ticket->resolved_at),
            ExportColumn::make('Closed on', fn (TicketRow $row) => $row->ticket->closed_at),
            ExportColumn::make('Times reopened', fn (TicketRow $row) => $row->ticket->reopen_count),
        ];
    }
}
