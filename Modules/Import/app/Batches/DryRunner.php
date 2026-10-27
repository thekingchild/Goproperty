<?php

declare(strict_types=1);

namespace Modules\Import\Batches;

use Closure;
use Illuminate\Support\Facades\DB;
use Modules\Import\Contracts\ImportAction;
use Modules\Import\Contracts\ImportColumn;
use Modules\Import\Contracts\ImportRun;
use Modules\Import\Contracts\ImportTarget;
use Modules\Import\Contracts\ImportTargets;
use Modules\Import\Contracts\RowIssue;
use Modules\Import\Mapping\SheetMapping;
use Modules\Import\Models\ImportBatch;
use Modules\Import\Reading\SpreadsheetFile;

/**
 * The dry run (DATA_IMPORT.md § 2.3): every mapped sheet, in commit order,
 * row by row through its target's validate() and simulate(). Nothing is
 * saved: targets only read, and the whole pass also runs inside a database
 * transaction that is always rolled back. Returns the report kept on the
 * batch: per sheet the rows, what would be created / updated / restored /
 * skipped, error and warning counts with the first of each (exact row,
 * column and message), a preview of the first rows, and the units and
 * yearly collections the file adds.
 *
 * The error workbook runs the same pass with `$onRow`, which sees every row
 * with its issues (the report itself keeps only the first ones).
 */
final readonly class DryRunner
{
    public function __construct(private ImportTargets $targets) {}

    /**
     * @param  (Closure(SheetMapping, int, array<int, mixed>, list<RowIssue>): void)|null  $onRow
     * @return array<string, mixed>
     */
    public function run(ImportBatch $batch, string $path, ?Closure $onRow = null): array
    {
        $file = SpreadsheetFile::open($path, $batch->file_type);
        $run = ImportRun::dryRun($batch->organization_id, $batch->id);
        $sheets = [];

        DB::beginTransaction();

        try {
            foreach ($this->ordered($batch) as [$sheet, $target]) {
                $sheets[] = $this->sheet($file, $sheet, $target, $run, $onRow);
            }
        } finally {
            DB::rollBack();
        }

        $total = static fn (string $key): int => (int) array_sum(array_column($sheets, $key));
        $counts = [];

        foreach (ImportAction::cases() as $action) {
            $counts[$action->value] = (int) array_sum(array_map(static fn (array $sheet): int => $sheet['counts'][$action->value], $sheets));
        }

        return [
            'sheets' => $sheets,
            'rows' => $total('rows'),
            'errors' => $total('errors'),
            'warnings' => $total('warnings'),
            'counts' => $counts,
            'units' => $total('units'),
            'yearly_collections_kobo' => $total('yearly_collections_kobo'),
        ];
    }

    /**
     * The mapped sheets with their targets, in commit order. A sheet mapped
     * to a target that is no longer registered comes with a null target.
     *
     * @return list<array{SheetMapping, ImportTarget|null}>
     */
    public function ordered(ImportBatch $batch): array
    {
        $pairs = [];

        foreach ($batch->sheets() as $sheet) {
            if ($sheet->target !== null) {
                $pairs[] = [$sheet, $this->targets->find($sheet->target)];
            }
        }

        usort($pairs, static fn (array $a, array $b): int => [$a[1]?->order() ?? PHP_INT_MAX, $a[0]->index] <=> [$b[1]?->order() ?? PHP_INT_MAX, $b[0]->index]);

        return $pairs;
    }

    /**
     * @param  (Closure(SheetMapping, int, array<int, mixed>, list<RowIssue>): void)|null  $onRow
     * @return array<string, mixed>
     */
    private function sheet(SpreadsheetFile $file, SheetMapping $sheet, ?ImportTarget $target, ImportRun $run, ?Closure $onRow): array
    {
        $limit = self::setting('report_issues', 100);
        $previewRows = self::setting('preview_rows', 5);
        $maxRows = self::setting('max_rows_per_sheet', 10_000);

        $report = [
            'index' => $sheet->index,
            'name' => $sheet->name,
            'target' => $sheet->target,
            'label' => $target?->label() ?? (string) $sheet->target,
            'rows' => 0,
            'counts' => array_fill_keys(array_map(static fn (ImportAction $a): string => $a->value, ImportAction::cases()), 0),
            'errors' => 0,
            'warnings' => 0,
            'issues' => [],
            'preview' => ['columns' => [], 'rows' => []],
            'units' => 0,
            'yearly_collections_kobo' => 0,
        ];
        $shown = ['error' => 0, 'warning' => 0];

        $sheetError = function (string $message) use (&$report, $sheet, $onRow): void {
            $report['errors']++;
            $report['issues'][] = ['row' => null, 'column' => null, 'header' => null, 'letter' => null, 'severity' => 'error', 'message' => $message];

            if ($onRow !== null) {
                $onRow($sheet, 0, [], [RowIssue::error(null, $message)]);
            }
        };

        if ($target === null) {
            $sheetError(__('Nothing can import ":kind" any more. Choose what this sheet holds again.', ['kind' => (string) $sheet->target]));

            return $report;
        }

        $missing = $sheet->missingRequired($target);

        if ($missing !== []) {
            $sheetError(__('Choose the file column for :columns: :count required.', [
                'columns' => implode(', ', array_map(static fn (ImportColumn $c): string => $c->label, $missing)),
                'count' => count($missing) === 1 ? __('it is') : __('they are'),
            ]));

            return $report;
        }

        $labels = [];

        foreach ($target->columns() as $column) {
            $labels[$column->key] = $column->label;
        }

        foreach ($file->rows($sheet->index, $sheet->headerRow + 1) as $number => $cells) {
            $row = $sheet->row($number, $cells);

            if ($row->isBlank()) {
                continue;
            }

            if (++$report['rows'] > $maxRows) {
                $report['rows'] = $maxRows;
                $sheetError(__('This sheet has more than :max rows. Split it into files of :max rows or fewer.', ['max' => number_format($maxRows)]));

                break;
            }

            $issues = $target->validate($row, $run);
            $errors = array_filter($issues, static fn (RowIssue $issue): bool => $issue->isError());

            foreach ($issues as $issue) {
                $severity = $issue->severity->value;
                $report[$severity === 'error' ? 'errors' : 'warnings']++;

                if ($limit > $shown[$severity]++) {
                    $report['issues'][] = [
                        'row' => $number,
                        'column' => $issue->column,
                        'header' => $issue->column !== null ? ($sheet->headerFor($issue->column) ?? $labels[$issue->column] ?? $issue->column) : null,
                        'letter' => $issue->column !== null ? $sheet->letterFor($issue->column) : null,
                        'severity' => $severity,
                        'message' => $issue->message,
                    ];
                }
            }

            if ($errors === []) {
                $plan = $target->simulate($row, $run);
                $report['counts'][$plan->action->value]++;
                $report['units'] += $plan->units;
                $report['yearly_collections_kobo'] += $plan->yearlyCollectionsKobo;

                if (count($report['preview']['rows']) < $previewRows && $plan->preview !== []) {
                    if ($report['preview']['columns'] === []) {
                        $report['preview']['columns'] = array_map(static fn (string $key): string => $labels[$key] ?? $key, array_keys($plan->preview));
                    }

                    $report['preview']['rows'][] = ['row' => $number, 'action' => $plan->action->value, 'values' => array_values($plan->preview)];
                }
            }

            if ($onRow !== null) {
                $onRow($sheet, $number, $cells, $issues);
            }
        }

        $report['issues_truncated'] = $shown['error'] > $limit || $shown['warning'] > $limit;

        return $report;
    }

    private static function setting(string $key, int $default): int
    {
        $value = config("import.{$key}");

        return is_int($value) && $value > 0 ? $value : $default;
    }
}
