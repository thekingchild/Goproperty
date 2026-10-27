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
use Modules\Collections\Enums\LineDirection;
use Modules\Collections\Enums\MatchStatus;
use Modules\Collections\Models\BankStatementImport;
use Modules\Collections\Models\BankStatementLine;

/**
 * The lines of bank statements (a statement's page,
 * /o/{slug}/statements/{id}, Livewire\StatementShow) and their export
 * (P02-07): one statement's lines in the file's order, or every line of
 * the organization newest first; filtered by direction, match status and a
 * search over the narration, the other party and the reference. Credits
 * still to match are the candidates for matching (P03-07b); debits are
 * shown for the record, never offered. No contact details beyond what the
 * bank printed in the narration.
 *
 * @extends QueryListExport<BankStatementLine>
 */
final class StatementLineList extends QueryListExport
{
    public function key(): string
    {
        return 'bank_statement_lines';
    }

    public function title(): string
    {
        return 'Bank statement lines';
    }

    public function filters(array $input): array
    {
        return ListFilters::set([
            'statement' => ListFilters::id($input['statement'] ?? null),
            'direction' => ListFilters::option($input['direction'] ?? null, LineDirection::values()),
            'status' => ListFilters::option($input['status'] ?? null, MatchStatus::values()),
            'search' => ListFilters::search($input['search'] ?? null),
        ]);
    }

    public function authorize(User $user, array $filters): void
    {
        Gate::forUser($user)->authorize('export', BankStatementImport::class);
    }

    public function query(User $user, array $filters): Builder
    {
        $direction = LineDirection::tryFrom((string) ($filters['direction'] ?? ''));
        $status = MatchStatus::tryFrom((string) ($filters['status'] ?? ''));
        $search = is_string($filters['search'] ?? null) ? $filters['search'] : null;
        $statement = is_int($filters['statement'] ?? null) ? $filters['statement'] : null;

        $query = BankStatementLine::query()
            ->when($statement !== null, fn (Builder $query) => $query->where('bank_statement_import_id', $statement))
            ->when($direction !== null, fn (Builder $query) => $query->where('direction', $direction))
            ->when($status !== null, fn (Builder $query) => $query->where('match_status', $status))
            ->when($search !== null, function (Builder $query) use ($search): void {
                $like = ListFilters::like((string) $search);

                $query->where(fn (Builder $query) => $query
                    ->where('narration', 'like', $like)
                    ->orWhere('counterparty_name', 'like', $like)
                    ->orWhere('reference', 'like', $like));
            });

        return $statement !== null
            ? $query->orderBy('row_number')->orderBy('id')
            : $query->orderByDesc('transaction_date')->orderByDesc('id');
    }

    protected function exportQuery(User $user, array $filters): Builder
    {
        return $this->query($user, $filters)->with(['bankAccount', 'statement:id,file_name']);
    }

    public function columns(User $user): array
    {
        return [
            ExportColumn::make('Date', fn (BankStatementLine $line) => ExportCell::date($line->transaction_date)),
            ExportColumn::make('Narration', fn (BankStatementLine $line) => $line->narration),
            ExportColumn::make('Sender or beneficiary', fn (BankStatementLine $line) => $line->counterparty_name),
            ExportColumn::make('Reference', fn (BankStatementLine $line) => $line->reference),
            ExportColumn::make('Credit', fn (BankStatementLine $line) => $line->direction === LineDirection::Credit ? $line->amount_kobo : null),
            ExportColumn::make('Debit', fn (BankStatementLine $line) => $line->direction === LineDirection::Debit ? $line->amount_kobo : null),
            ExportColumn::make('Balance', fn (BankStatementLine $line) => $line->balance_kobo),
            ExportColumn::make('Match status', fn (BankStatementLine $line) => $line->match_status),
            ExportColumn::make('Bank account (masked)', fn (BankStatementLine $line): string => $line->bankAccount !== null ? $line->bankAccount->bank_name.' '.$line->bankAccount->masked() : 'Removed account'),
            ExportColumn::make('Statement', fn (BankStatementLine $line) => $line->statement?->file_name),
            ExportColumn::make('Row in the file', fn (BankStatementLine $line): int => $line->row_number),
        ];
    }
}
