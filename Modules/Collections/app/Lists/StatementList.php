<?php

declare(strict_types=1);

namespace Modules\Collections\Lists;

use App\Exports\ExportCell;
use App\Exports\ExportColumn;
use App\Exports\ListFilters;
use App\Exports\QueryListExport;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Modules\Collections\Enums\StatementStatus;
use Modules\Collections\Models\BankStatementImport;

/**
 * The uploaded bank statements (/o/{slug}/statements,
 * Livewire\StatementIndex) and their export (P02-07): newest first, with
 * the bank account (masked), the period, the report's counts and who
 * uploaded it; filtered by account and status. No contact details, so
 * staff may export it while impersonating, audited.
 *
 * @extends QueryListExport<BankStatementImport>
 */
final class StatementList extends QueryListExport
{
    public function key(): string
    {
        return 'bank_statements';
    }

    public function title(): string
    {
        return 'Bank statements';
    }

    public function filters(array $input): array
    {
        return ListFilters::set([
            'account' => ListFilters::id($input['account'] ?? null),
            'status' => ListFilters::option($input['status'] ?? null, StatementStatus::values()),
        ]);
    }

    public function authorize(User $user, array $filters): void
    {
        Gate::forUser($user)->authorize('export', BankStatementImport::class);
    }

    public function query(User $user, array $filters): Builder
    {
        $status = StatementStatus::tryFrom((string) ($filters['status'] ?? ''));

        return BankStatementImport::query()
            ->when(is_int($filters['account'] ?? null), fn (Builder $query) => $query->where('organization_bank_account_id', $filters['account']))
            ->when($status !== null, fn (Builder $query) => $query->where('status', $status))
            ->orderByDesc('id');
    }

    protected function exportQuery(User $user, array $filters): Builder
    {
        return $this->query($user, $filters)->with(['bankAccount', 'uploadedBy:id,name']);
    }

    public function columns(User $user): array
    {
        return [
            ExportColumn::make('Bank account (masked)', fn (BankStatementImport $statement): string => $statement->accountLabel()),
            ExportColumn::make('File', fn (BankStatementImport $statement) => $statement->file_name),
            ExportColumn::make('Format', fn (BankStatementImport $statement) => $statement->format),
            ExportColumn::make('From', fn (BankStatementImport $statement) => ExportCell::date($statement->period_start)),
            ExportColumn::make('To', fn (BankStatementImport $statement) => ExportCell::date($statement->period_end)),
            ExportColumn::make('Transactions read', fn (BankStatementImport $statement): int => $statement->lines_total),
            ExportColumn::make('Lines added', fn (BankStatementImport $statement): int => $statement->lines_added),
            ExportColumn::make('Credits added', fn (BankStatementImport $statement): int => $statement->lines_credit),
            ExportColumn::make('Debits (not matched)', fn (BankStatementImport $statement): int => $statement->lines_debit),
            ExportColumn::make('Duplicates skipped', fn (BankStatementImport $statement): int => $statement->lines_duplicate),
            ExportColumn::make('Unreadable rows', fn (BankStatementImport $statement): int => $statement->lines_unreadable),
            ExportColumn::make('Status', fn (BankStatementImport $statement) => $statement->status),
            ExportColumn::make('Uploaded by', fn (BankStatementImport $statement) => $statement->uploadedBy?->name),
            ExportColumn::make('Uploaded on', fn (BankStatementImport $statement) => $statement->created_at),
        ];
    }
}
