<?php

declare(strict_types=1);

namespace Modules\Expenses\Lists;

use App\Exports\ExportCell;
use App\Exports\ExportColumn;
use App\Exports\ListExport;
use App\Exports\ListFilters;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Modules\Expenses\Enums\ExpenseCategory;
use Modules\Expenses\Models\Expense;
use Modules\Expenses\Recording\ExpenseInput;
use Modules\Properties\Contracts\BuildingAccess;

/**
 * The expenses list (/o/{slug}/expenses, Livewire\ExpenseIndex) and its
 * export (P02-07, AGENT.md § 5): the organization's expenses the member
 * sees — the buildings' (an assigned agent: theirs only, through
 * Modules\Properties\Contracts\BuildingAccess) and, for owners and
 * administrators (`viewOrganizationExpenses`), the organization's own with
 * no building — active or archived, filtered by category, building (or
 * "no building"), and a date range, with a search over what was paid for,
 * who was paid and the reference; latest date paid first. Rows carry the
 * building, unit and recorder names (ExpenseRows). No contact details, so
 * staff may export it while impersonating, audited.
 *
 * Built like App\Exports\QueryListExport (the page paginates query(), the
 * export reads it CHUNK rows at a time), with ExpenseRow values.
 */
final class ExpenseList implements ListExport
{
    public const int CHUNK = 500;

    /** The building filter's value for the organization's own expenses. */
    public const string NO_BUILDING = 'none';

    public function __construct(
        private readonly BuildingAccess $access,
        private readonly ExpenseRows $rows,
    ) {}

    public function key(): string
    {
        return 'expenses';
    }

    public function title(): string
    {
        return 'Expenses';
    }

    public function filters(array $input): array
    {
        $building = $input['building'] ?? null;
        $from = ExpenseInput::date(is_string($input['from'] ?? null) ? $input['from'] : null);
        $to = ExpenseInput::date(is_string($input['to'] ?? null) ? $input['to'] : null);

        return ListFilters::set([
            'search' => ListFilters::search($input['search'] ?? null),
            'archived' => ListFilters::flag($input['archived'] ?? false),
            'category' => ListFilters::option($input['category'] ?? null, ExpenseCategory::values()),
            'building' => $building === self::NO_BUILDING ? self::NO_BUILDING : ListFilters::id($building),
            'from' => $from?->toDateString(),
            'to' => $to?->toDateString(),
        ]);
    }

    public function authorize(User $user, array $filters): void
    {
        Gate::forUser($user)->authorize('export', Expense::class);
    }

    /**
     * The list's rows: organization scope, what the member sees, filters,
     * search, latest date paid first.
     *
     * @param  array<string, string|int|bool>  $filters  cleaned
     * @return Builder<Expense>
     */
    public function query(User $user, array $filters): Builder
    {
        $search = is_string($filters['search'] ?? null) ? $filters['search'] : null;
        $category = is_string($filters['category'] ?? null) ? $filters['category'] : null;
        $building = $filters['building'] ?? null;
        $from = is_string($filters['from'] ?? null) ? $filters['from'] : null;
        $to = is_string($filters['to'] ?? null) ? $filters['to'] : null;
        $limited = $this->access->limitedTo($user);
        $seesOrganization = Gate::forUser($user)->allows('viewOrganizationExpenses', Expense::class);

        return Expense::query()
            ->when(($filters['archived'] ?? false) === true, fn (Builder $query) => $query->onlyTrashed())
            ->when(! $seesOrganization, fn (Builder $query) => $query->whereNotNull('property_id'))
            ->when($limited !== null, fn (Builder $query) => $query->whereIn('property_id', $limited ?? []))
            ->when($building === self::NO_BUILDING, fn (Builder $query) => $query->whereNull('property_id'))
            ->when(is_int($building), fn (Builder $query) => $query->where('property_id', $building))
            ->when($category !== null, fn (Builder $query) => $query->where('category', $category))
            ->when($from !== null, fn (Builder $query) => $query->where('spent_on', '>=', $from))
            ->when($to !== null, fn (Builder $query) => $query->where('spent_on', '<=', $to))
            ->when($search !== null, fn (Builder $query) => $query->where(fn (Builder $query) => $query
                ->where('description', 'like', ListFilters::like((string) $search))
                ->orWhere('vendor_name', 'like', ListFilters::like((string) $search))
                ->orWhere('reference', 'like', ListFilters::like((string) $search))))
            ->orderByDesc('spent_on')
            ->orderByDesc('id');
    }

    /** @return iterable<ExpenseRow> */
    public function rows(User $user, array $filters): iterable
    {
        foreach ($this->query($user, $filters)->lazy(self::CHUNK)->chunk(self::CHUNK) as $chunk) {
            yield from $this->rows->rows($chunk);
        }
    }

    public function count(User $user, array $filters): int
    {
        return $this->query($user, $filters)->toBase()->getCountForPagination();
    }

    public function columns(User $user): array
    {
        return [
            ExportColumn::make('Date paid', fn (ExpenseRow $row) => ExportCell::date($row->expense->spent_on)),
            ExportColumn::make('Category', fn (ExpenseRow $row) => $row->expense->category),
            ExportColumn::make('Description', fn (ExpenseRow $row) => $row->expense->description),
            ExportColumn::make('Amount', fn (ExpenseRow $row) => $row->expense->amount_kobo),
            ExportColumn::make('Building', fn (ExpenseRow $row) => $row->buildingName),
            ExportColumn::make('Unit', fn (ExpenseRow $row) => $row->unitLabel),
            ExportColumn::make('Paid to', fn (ExpenseRow $row) => $row->expense->vendor_name),
            ExportColumn::make('Payment method', fn (ExpenseRow $row) => $row->expense->payment_method),
            ExportColumn::make('Reference', fn (ExpenseRow $row) => $row->expense->reference),
            ExportColumn::make('Charged to owner', fn (ExpenseRow $row) => $row->expense->charged_to_owner ? 'Yes' : 'No'),
            ExportColumn::make('Note', fn (ExpenseRow $row) => $row->expense->note),
            ExportColumn::make('Receipt files', fn (ExpenseRow $row) => $row->receipts),
            ExportColumn::make('Recorded by', fn (ExpenseRow $row) => $row->recordedBy),
            ExportColumn::make('Recorded on', fn (ExpenseRow $row) => $row->expense->created_at),
            ExportColumn::make('Archived on', fn (ExpenseRow $row) => $row->expense->deleted_at),
            ExportColumn::make('Reason archived', fn (ExpenseRow $row) => $row->expense->archive_reason),
        ];
    }
}
