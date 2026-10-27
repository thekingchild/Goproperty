<?php

declare(strict_types=1);

namespace Modules\Import\Lists;

use App\Exports\ExportColumn;
use App\Exports\QueryListExport;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Modules\Import\Contracts\ImportTargets;
use Modules\Import\Models\ImportBatch;

/**
 * The import history (/o/{slug}/imports, Livewire\ImportIndex) and its
 * export (P02-07): every batch of the organization, newest first, with who
 * ran it, its status, its rows and what the commit did (DATA_IMPORT.md
 * § 5). The uploaded file itself is not part of it.
 *
 * @extends QueryListExport<ImportBatch>
 */
final class ImportBatchList extends QueryListExport
{
    public function __construct(private readonly ImportTargets $targets) {}

    public function key(): string
    {
        return 'imports';
    }

    public function title(): string
    {
        return 'Imports';
    }

    public function filters(array $input): array
    {
        return [];
    }

    public function authorize(User $user, array $filters): void
    {
        Gate::forUser($user)->authorize('export', ImportBatch::class);
    }

    public function query(User $user, array $filters): Builder
    {
        return ImportBatch::query()->latest('id');
    }

    protected function exportQuery(User $user, array $filters): Builder
    {
        return $this->query($user, $filters)->with(['createdBy:id,name', 'committedBy:id,name']);
    }

    public function columns(User $user): array
    {
        return [
            ExportColumn::make('File', fn (ImportBatch $batch) => $batch->file_name),
            ExportColumn::make('What', fn (ImportBatch $batch): string => $batch->kind === 'workbook' ? 'Workbook' : ($this->targets->find($batch->kind)?->label() ?? $batch->kind)),
            ExportColumn::make('Status', fn (ImportBatch $batch) => $batch->status),
            ExportColumn::make('Rows', fn (ImportBatch $batch): int => $batch->rows_total),
            ExportColumn::make('Result', fn (ImportBatch $batch): string => self::result($batch)),
            ExportColumn::make('Uploaded by', fn (ImportBatch $batch) => $batch->createdBy?->name),
            ExportColumn::make('Uploaded on', fn (ImportBatch $batch) => $batch->created_at),
            ExportColumn::make('Committed by', fn (ImportBatch $batch) => $batch->committedBy?->name),
            ExportColumn::make('Committed on', fn (ImportBatch $batch) => $batch->committed_at),
            ExportColumn::make('Rolled back on', fn (ImportBatch $batch) => $batch->rolled_back_at),
        ];
    }

    /** "owners: 3 created, 1 updated; units: 12 created". */
    private static function result(ImportBatch $batch): string
    {
        $parts = [];

        foreach ($batch->result_counts['targets'] ?? [] as $target => $counts) {
            $done = [];

            foreach ($counts as $action => $count) {
                if ($count > 0) {
                    $done[] = "{$count} {$action}";
                }
            }

            if ($done !== []) {
                $parts[] = $target.': '.implode(', ', $done);
            }
        }

        return implode('; ', $parts);
    }
}
