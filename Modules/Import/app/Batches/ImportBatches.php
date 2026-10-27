<?php

declare(strict_types=1);

namespace Modules\Import\Batches;

use App\Media\MediaUploads;
use App\Media\UploadRefused;
use App\Media\UploadValidator;
use App\Models\User;
use App\Tenancy\OrganizationContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Modules\Import\Contracts\ImportTarget;
use Modules\Import\Contracts\ImportTargets;
use Modules\Import\Enums\ImportFileType;
use Modules\Import\Enums\ImportStatus;
use Modules\Import\Jobs\CommitImportSheet;
use Modules\Import\Mapping\MappingSuggester;
use Modules\Import\Mapping\SheetMapping;
use Modules\Import\Models\ImportBatch;
use Modules\Import\Models\ImportColumnMapping;
use Modules\Import\Reading\LocalCopy;
use Modules\Import\Reading\SpreadsheetFile;
use Throwable;

/**
 * The only writer of import batches (DATA_IMPORT.md § 2, § 5), apart from
 * the commit job's progress: upload, mapping, dry run, commit, rollback.
 * Each step checks ImportBatchPolicy for the acting user (so the
 * suspension and impersonation guards apply: commit and rollback are
 * refused while staff impersonate) and is written to the audit log (log
 * `imports`, the batch as subject; counts only, never cell values).
 */
final readonly class ImportBatches
{
    public const string LOG = 'imports';

    public function __construct(
        private ImportTargets $targets,
        private MappingSuggester $suggester,
        private DryRunner $dryRunner,
        private UploadValidator $validator,
        private MediaUploads $uploads,
        private OrganizationContext $context,
    ) {}

    /**
     * Stores the file in the media library (collection `imports`, checked
     * by UploadValidator), reads each sheet's header and suggests a mapping.
     * `$target` says what a CSV file holds when the agency chose it.
     *
     * @throws UploadRefused when the file is not an .xlsx or .csv spreadsheet, or cannot be read
     */
    public function upload(User $by, UploadedFile $file, ?string $target = null): ImportBatch
    {
        Gate::forUser($by)->authorize('create', ImportBatch::class);

        if ($target !== null && $this->targets->find($target) === null) {
            throw ValidationException::withMessages(['target' => __('Choose what the file holds.')]);
        }

        $checked = $this->validator->check(ImportBatch::COLLECTION, $file);
        $type = ImportFileType::fromMime($checked->mime) ?? throw new UploadRefused(__('Upload an Excel (.xlsx) or CSV file.'));
        $sheets = $this->readSheets((string) $file->getRealPath(), $type, $target);

        return DB::transaction(function () use ($by, $file, $type, $sheets): ImportBatch {
            $batch = new ImportBatch([
                'kind' => ImportBatch::WORKBOOK,
                'file_type' => $type,
                'file_name' => MediaUploads::label($file->getClientOriginalName()).'.'.$type->value,
                'status' => ImportStatus::Uploaded,
                'created_by_user_id' => $by->id,
            ]);
            $batch->setSheets($sheets);
            $batch->kind = $this->kindOf($sheets);
            $batch->save();

            $media = $this->uploads->add($batch, ImportBatch::COLLECTION, $file);
            $batch->media_id = $media->id;
            $batch->save();

            $this->audit('import.uploaded', $batch, $by, 'Import file uploaded', ['sheets' => count($sheets)]);

            return $batch;
        });
    }

    /**
     * Saves the agency's mapping (DATA_IMPORT.md § 2.2): per sheet, the
     * target (or none) and the file column feeding each target column.
     * Changing it means the dry run must run again. With `$saveAs`, each
     * mapped sheet's columns are saved for reuse under that name.
     *
     * @param  array<int|string, array{target?: string|null, columns?: array<string, int|string|null>}>  $input  by sheet index
     *
     * @throws ValidationException
     */
    public function map(User $by, ImportBatch $batch, array $input, ?string $saveAs = null): ImportBatch
    {
        Gate::forUser($by)->authorize('update', $batch);

        $sheets = [];
        $errors = [];
        $used = [];

        foreach ($batch->sheets() as $sheet) {
            $given = $input[$sheet->index] ?? [];
            $targetKey = is_string($given['target'] ?? null) && $given['target'] !== '' ? $given['target'] : null;
            $target = $targetKey !== null ? $this->targets->find($targetKey) : null;

            if ($targetKey !== null && $target === null) {
                $errors["sheets.{$sheet->index}.target"] = __('Choose what this sheet holds.');

                continue;
            }

            if ($target === null) {
                $sheets[] = $sheet->mappedTo(null, []);

                continue;
            }

            if (isset($used[$target->key()])) {
                $errors["sheets.{$sheet->index}.target"] = __('Only one sheet can hold :kind; “:sheet” already does.', ['kind' => $target->label(), 'sheet' => $used[$target->key()]]);

                continue;
            }

            $used[$target->key()] = $sheet->name;
            $sheets[] = $sheet->mappedTo($target->key(), $this->columns($target, $sheet, is_array($given['columns'] ?? null) ? $given['columns'] : [], $errors));
        }

        $saveAs = $saveAs !== null && trim($saveAs) !== '' ? trim($saveAs) : null;

        if ($saveAs !== null && (mb_strlen($saveAs) > 80 || preg_match('/[<>]/', $saveAs) === 1)) {
            $errors['saveAs'] = __('Use a plain name of up to 80 characters.');
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return DB::transaction(function () use ($by, $batch, $sheets, $saveAs): ImportBatch {
            $batch = $this->locked($batch);
            $this->ensure($batch->status->isPreparing(), __('This import is past the mapping step.'));

            $batch->setSheets($sheets);
            $batch->kind = $this->kindOf($sheets);
            $batch->status = ImportStatus::Uploaded;
            $batch->dry_run_report = null;
            $batch->dry_run_at = null;
            $batch->save();

            if ($saveAs !== null) {
                foreach ($sheets as $sheet) {
                    if ($sheet->target !== null && $sheet->columns !== []) {
                        $mapping = [];

                        foreach ($sheet->columns as $key => $position) {
                            $mapping[MappingSuggester::normalise($sheet->headers[$position] ?? '')] = $key;
                        }

                        ImportColumnMapping::query()->updateOrCreate(
                            ['kind' => $sheet->target, 'name' => $saveAs],
                            ['mapping' => array_filter($mapping, static fn (string $key, string $header): bool => $header !== '', ARRAY_FILTER_USE_BOTH), 'created_by_user_id' => $by->id],
                        );
                    }
                }
            }

            $this->audit('import.mapped', $batch, $by, 'Import columns mapped', ['saved_as' => $saveAs]);

            return $batch;
        });
    }

    /**
     * Validates and simulates the whole file without saving anything
     * (DATA_IMPORT.md § 2.3) and keeps the report on the batch. Passed when
     * there are rows and no errors (warnings are accepted).
     *
     * @throws ImportRefused
     */
    public function dryRun(User $by, ImportBatch $batch): ImportBatch
    {
        Gate::forUser($by)->authorize('update', $batch);

        $this->ensure($batch->status->isPreparing(), __('This import is past the dry run.'));
        $this->ensure($this->dryRunner->ordered($batch) !== [], __('Choose what at least one sheet holds before the dry run.'));
        $media = $batch->file() ?? throw new ImportRefused(__('The uploaded file is no longer kept. Upload it again.'));

        $report = LocalCopy::using($media, fn (string $path): array => $this->dryRunner->run($batch, $path));
        $passed = $report['errors'] === 0 && $report['rows'] > 0;

        return DB::transaction(function () use ($by, $batch, $report, $passed): ImportBatch {
            $batch = $this->locked($batch);
            $this->ensure($batch->status->isPreparing(), __('This import is past the dry run.'));

            $batch->dry_run_report = $report;
            $batch->dry_run_at = now();
            $batch->rows_total = $report['rows'];
            $batch->status = $passed ? ImportStatus::DryRunPassed : ImportStatus::DryRunFailed;
            $batch->save();

            $this->audit('import.dry_run', $batch, $by, $passed ? 'Import dry run passed' : 'Import dry run found errors', [
                'rows' => $report['rows'], 'errors' => $report['errors'], 'warnings' => $report['warnings'], 'counts' => $report['counts'],
            ]);

            return $batch;
        });
    }

    /**
     * Starts the queued commit (DATA_IMPORT.md § 2.4), only after a passed
     * dry run: one job per sheet on the `imports` queue, in dependency
     * order, each resuming from the batch's cursor.
     *
     * @throws ImportRefused
     */
    public function commit(User $by, ImportBatch $batch): ImportBatch
    {
        Gate::forUser($by)->authorize('commit', $batch);

        $this->ensure($batch->file() !== null, __('The uploaded file is no longer kept. Upload it again.'));

        [$batch, $first] = DB::transaction(function () use ($by, $batch): array {
            $batch = $this->locked($batch);
            $this->ensure($batch->status === ImportStatus::DryRunPassed, __('Run a dry run without errors before committing.'));

            $first = $this->dryRunner->ordered($batch)[0] ?? null;
            $this->ensure($first !== null && $first[1] !== null, __('Run the dry run again.'));
            [$sheet, $target] = $first;

            $batch->status = ImportStatus::Committing;
            $batch->committed_by_user_id = $by->id;
            $batch->committed_at = now();
            $batch->rows_done = 0;
            $batch->result_counts = ['targets' => [], 'skipped' => []];
            $batch->failure_reason = null;
            $batch->commit_cursor = ['target' => $target->key(), 'row' => $sheet->headerRow + 1];
            $batch->save();

            $this->audit('import.commit_started', $batch, $by, 'Import commit started', ['rows' => $batch->rows_total]);

            return [$batch, $target->key()];
        });

        CommitImportSheet::dispatch($batch->organization_id, $batch->id, $first);

        return $batch;
    }

    /**
     * Rolls a committed batch back (DATA_IMPORT.md § 2 "Undo"): within the
     * rollback window (7 days from the commit) and only while none of its
     * records has been used. Every target soft deletes what the batch
     * created or restored, in reverse dependency order, in one transaction.
     *
     * @throws ImportRefused
     */
    public function rollback(User $by, ImportBatch $batch): ImportBatch
    {
        Gate::forUser($by)->authorize('rollback', $batch);

        return DB::transaction(function () use ($by, $batch): ImportBatch {
            $batch = $this->locked($batch);
            $this->ensure($batch->status->hasCommitted(), __('Only an import that was committed can be rolled back.'));
            $this->ensure($batch->rollbackEndsAt()?->isFuture() === true, __('Imports can be rolled back for :days days. After that, change the records one by one.', ['days' => config('import.rollback_days', 7)]));

            $targets = array_reverse(array_values(array_filter(array_map(static fn (array $pair): ?ImportTarget => $pair[1], $this->dryRunner->ordered($batch)))));

            foreach ($targets as $target) {
                $this->ensure(! $target->usedSince($batch->id), __('Some :kind from this import have been used since (for example a payment was recorded or a bill sent), so it can no longer be rolled back. Change the records one by one.', ['kind' => mb_strtolower($target->label())]));
            }

            $archived = [];

            foreach ($targets as $target) {
                $archived[$target->key()] = $target->rollback($batch->id);
            }

            $batch->status = ImportStatus::RolledBack;
            $batch->rolled_back_at = now();
            $batch->rolled_back_by_user_id = $by->id;
            $batch->save();

            $this->audit('import.rolled_back', $batch, $by, 'Import rolled back', ['archived' => $archived]);

            return $batch;
        });
    }

    /** @param  array<string, mixed>  $properties */
    public function audit(string $event, ImportBatch $batch, ?User $by, string $description, array $properties = []): void
    {
        $entry = activity(self::LOG)->event($event)->performedOn($batch)->withProperties(['file' => $batch->file_name, ...$properties]);

        if ($by !== null) {
            $entry->causedBy($by);
        }

        $entry->log($description);
    }

    /**
     * @param  array<string, int|string|null>  $given  column key => header position
     * @param  array<string, mixed>  $errors  field => message, for ValidationException
     * @return array<string, int>
     */
    private function columns(ImportTarget $target, SheetMapping $sheet, array $given, array &$errors): array
    {
        $columns = [];
        $taken = [];

        foreach ($target->columns() as $column) {
            $position = $given[$column->key] ?? null;

            if ($position === null || $position === '') {
                continue;
            }

            if (! is_numeric($position) || (int) $position < 0 || (int) $position >= count($sheet->headers)) {
                $errors["sheets.{$sheet->index}.columns.{$column->key}"] = __('Choose a column of this sheet.');

                continue;
            }

            $position = (int) $position;

            if (isset($taken[$position])) {
                $errors["sheets.{$sheet->index}.columns.{$column->key}"] = __('“:header” already feeds :other.', ['header' => $sheet->headers[$position], 'other' => $taken[$position]]);

                continue;
            }

            $taken[$position] = $column->label;
            $columns[$column->key] = $position;
        }

        return $columns;
    }

    /**
     * @return list<SheetMapping>
     *
     * @throws UploadRefused
     */
    private function readSheets(string $path, ImportFileType $type, ?string $target): array
    {
        try {
            $file = SpreadsheetFile::open($path, $type);
            $sheets = [];

            foreach ($file->sheets() as $sheet) {
                $header = $file->header($sheet['index']);
                $mapping = new SheetMapping($sheet['index'], $sheet['name'], $header['row'] ?? 1, $header['headers'] ?? []);
                $sheets[] = $header === null ? $mapping : $this->suggester->suggest($mapping, $type === ImportFileType::Csv ? $target : null);
            }
        } catch (Throwable $e) {
            report($e);

            throw new UploadRefused(__('The file could not be read as a spreadsheet. Open it in Excel, save it again as .xlsx or .csv, and upload it again.'));
        }

        if (array_filter($sheets, static fn (SheetMapping $sheet): bool => $sheet->headers !== []) === []) {
            throw new UploadRefused(__('The file has no rows. Put the column names in the first row and the records below them.'));
        }

        return $sheets;
    }

    /** @param  list<SheetMapping>  $sheets */
    private function kindOf(array $sheets): string
    {
        $targets = array_values(array_unique(array_filter(array_map(static fn (SheetMapping $sheet): ?string => $sheet->target, $sheets))));

        return count($sheets) === 1 && count($targets) === 1 ? $targets[0] : ImportBatch::WORKBOOK;
    }

    private function locked(ImportBatch $batch): ImportBatch
    {
        $this->ensure($this->context->has() && $batch->organization_id === $this->context->id(), __('This import belongs to another organization.'));

        return ImportBatch::query()->whereKey($batch->id)->lockForUpdate()->firstOrFail();
    }

    /** @throws ImportRefused */
    private function ensure(bool $condition, string $message): void
    {
        if (! $condition) {
            throw new ImportRefused($message);
        }
    }
}
