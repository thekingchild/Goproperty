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
 * status ("open": waiting for the organization), building and whether
 * the ticket is shared with the building's owner (P04-06), with a
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

    /** The assignee filter's values: assigned to the member looking, and assigned to nobody. */
    public const string MINE = 'me';

    public const string UNASSIGNED = 'none';

    /** The owner filter's values (P04-06): shared with the building's owner, and not shared. */
    public const string SHARED = 'shared';

    public const string NOT_SHARED = 'hidden';

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
            'assignee' => ListFilters::option($input['assignee'] ?? null, [self::MINE, self::UNASSIGNED]),
            'building' => ListFilters::id($input['building'] ?? null),
            'owner' => ListFilters::option($input['owner'] ?? null, [self::SHARED, self::NOT_SHARED]),
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
        $assignee = (string) ($filters['assignee'] ?? '');
        $owner = (string) ($filters['owner'] ?? '');
        $limited = $this->access->limitedTo($user);

        return Ticket::query()
            ->where('status', '!=', TicketStatus::Draft)
            ->when($limited !== null, fn (Builder $query) => $query->whereIn('property_id', $limited ?? []))
            ->when($status === self::OPEN, fn (Builder $query) => $query->whereIn('status', TicketStatus::open()))
            ->when(TicketStatus::tryFrom($status) !== null, fn (Builder $query) => $query->where('status', $status))
            ->when($building !== null, fn (Builder $query) => $query->where('property_id', $building))
            ->when($assignee === self::MINE, fn (Builder $query) => $query->where('assigned_to_user_id', $user->id))
            ->when($assignee === self::UNASSIGNED, fn (Builder $query) => $query->whereNull('assigned_to_user_id'))
            ->when($owner === self::SHARED, fn (Builder $query) => $query->whereNotNull('shared_with_owner_at'))
            ->when($owner === self::NOT_SHARED, fn (Builder $query) => $query->whereNull('shared_with_owner_at'))
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

    /**
     * The inbox's status tabs: how many tickets each status (and `open`,
     * and `all`) would list with the other filters as they are.
     *
     * @param  array<string, string|int|bool>  $filters  cleaned
     * @return array<string, int> status value, `open` or `all` => count
     */
    public function statusCounts(User $user, array $filters): array
    {
        unset($filters['status']);

        $byStatus = $this->query($user, $filters)->reorder()->toBase()
            ->select('status')->selectRaw('count(*) as aggregate')->groupBy('status')
            ->pluck('aggregate', 'status')->map(static fn (mixed $count): int => (int) $count)->all();

        $counts = [self::OPEN => 0, 'all' => array_sum($byStatus)];

        foreach (TicketStatus::sent() as $status) {
            $counts[$status->value] = $byStatus[$status->value] ?? 0;

            if ($status->isOpen()) {
                $counts[self::OPEN] += $counts[$status->value];
            }
        }

        return $counts;
    }

    /**
     * The inbox's assignee tabs: how many tickets are anyone's, the
     * member's own and nobody's, with the other filters as they are.
     *
     * @param  array<string, string|int|bool>  $filters  cleaned
     * @return array{all: int, me: int, none: int}
     */
    public function assigneeCounts(User $user, array $filters): array
    {
        unset($filters['assignee']);

        $row = $this->query($user, $filters)->reorder()->toBase()
            ->selectRaw('count(*) as total')
            ->selectRaw('coalesce(sum(case when assigned_to_user_id = ? then 1 else 0 end), 0) as mine', [$user->id])
            ->selectRaw('coalesce(sum(case when assigned_to_user_id is null then 1 else 0 end), 0) as unassigned')
            ->first();

        return [
            'all' => (int) ($row->total ?? 0),
            self::MINE => (int) ($row->mine ?? 0),
            self::UNASSIGNED => (int) ($row->unassigned ?? 0),
        ];
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
            // P04-06: empty when the building's owner does not see it.
            ExportColumn::make('Shared with owner on', fn (TicketRow $row) => $row->ticket->shared_with_owner_at),
        ];
    }
}
