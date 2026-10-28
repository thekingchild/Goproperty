<?php

declare(strict_types=1);

namespace Modules\OwnerStatements\Lists;

use App\Exports\ExportCell;
use App\Exports\ExportColumn;
use App\Exports\ListExport;
use App\Exports\ListFilters;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Modules\OwnerStatements\Models\OwnerStatementSettlement;
use Modules\OwnerStatements\Statements\MonthStatement;
use Modules\OwnerStatements\Statements\OwnerStatements;
use Modules\OwnerStatements\Statements\StatementMonth;

/**
 * An owner's months as the statements page lists them (P08-05; list
 * export, ARCHITECTURE.md § 6.13): the last 12 Lagos months, newest first,
 * each with its figures, Settled / Unsettled, the date paid and the
 * reference. Figures only: no tenant, no contact details. Filter: `owner`
 * (required: the owner profile's id).
 */
final readonly class OwnerStatementList implements ListExport
{
    public function __construct(private OwnerStatements $statements) {}

    public function key(): string
    {
        return 'owner_statements';
    }

    public function title(): string
    {
        return 'Owner statements';
    }

    public function filters(array $input): array
    {
        return ListFilters::set(['owner' => ListFilters::id($input['owner'] ?? null)]);
    }

    public function authorize(User $user, array $filters): void
    {
        $owner = $filters['owner'] ?? null;
        abort_unless(is_int($owner), 404);

        Gate::forUser($user)->authorize('exportOwner', [OwnerStatementSettlement::class, $owner]);
    }

    /** @return list<MonthStatement> */
    public function rows(User $user, array $filters): iterable
    {
        $owner = $filters['owner'] ?? null;

        return is_int($owner) ? $this->statements->months($owner, StatementMonth::current()->lastMonths()) : [];
    }

    public function count(User $user, array $filters): int
    {
        return StatementMonth::LISTED;
    }

    public function columns(User $user): array
    {
        return [
            ExportColumn::make('Month', fn (MonthStatement $row) => $row->month()->label()),
            ExportColumn::make('Status', fn (MonthStatement $row) => $row->statusLabel()),
            ExportColumn::make('Rent collected', fn (MonthStatement $row) => $row->statement->totals->rent),
            ExportColumn::make('Commission rate', fn (MonthStatement $row) => $row->statement->commissionRate() ?? 'Not set'),
            ExportColumn::make('Commission', fn (MonthStatement $row) => $row->statement->totals->commission),
            ExportColumn::make('Utilities collected', fn (MonthStatement $row) => $row->statement->totals->utilities),
            ExportColumn::make('Utilities pass to the owner', fn (MonthStatement $row) => $row->statement->utilitiesToOwner),
            ExportColumn::make('Expenses charged', fn (MonthStatement $row) => $row->statement->totals->expenses),
            ExportColumn::make('Net due to the owner', fn (MonthStatement $row) => $row->statement->totals->netDue),
            ExportColumn::make('Date paid', fn (MonthStatement $row) => $row->isSettled() ? ExportCell::date($row->settlement?->paid_on) : null),
            ExportColumn::make('Reference', fn (MonthStatement $row) => $row->isSettled() ? $row->settlement?->reference : null),
        ];
    }
}
