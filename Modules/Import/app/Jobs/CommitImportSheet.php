<?php

declare(strict_types=1);

namespace Modules\Import\Jobs;

use App\Enums\ChecklistItem;
use App\Models\Organization;
use App\Onboarding\OnboardingChecklist;
use App\Tenancy\OrganizationContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Modules\Import\Batches\DryRunner;
use Modules\Import\Batches\ImportBatches;
use Modules\Import\Contracts\ImportAction;
use Modules\Import\Contracts\ImportRow;
use Modules\Import\Contracts\ImportRun;
use Modules\Import\Contracts\ImportTarget;
use Modules\Import\Contracts\RowIssue;
use Modules\Import\Enums\ImportStatus;
use Modules\Import\Mapping\SheetMapping;
use Modules\Import\Models\ImportBatch;
use Modules\Import\Reading\LocalCopy;
use Modules\Import\Reading\SpreadsheetFile;
use RuntimeException;
use Throwable;

/**
 * Commits one sheet of an import batch (DATA_IMPORT.md § 2.4) on the
 * `imports` queue, inside the batch's organization (jobs start with no
 * context). Rows are written in chunks (config import.chunk_rows), each
 * chunk in one transaction together with the batch's progress and resume
 * point (`commit_cursor`), so a retried or repeated job carries on where
 * the last chunk ended and never writes a row twice. After
 * config import.job_seconds the job queues the rest of the sheet as a new
 * job, so every job stays well inside the 50 seconds a shared host's queue
 * tick gives it (AGENT.md § 4). At the end of the sheet it queues the next
 * sheet in dependency order, or marks the batch committed.
 *
 * Each row is validated again against the database as it is now: a row
 * that no longer passes (say its property was archived after the dry run)
 * is skipped and reported, not forced in.
 */
final class CommitImportSheet implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /** Seconds; the queue tick's budget per job (QUEUE_TICK_JOB_TIMEOUT). */
    public int $timeout = 50;

    public int $tries = 3;

    public int $backoff = 30;

    /** Skipped rows listed on the report (the counts are exact). */
    private const int SKIPPED_LISTED = 200;

    public function __construct(
        public readonly int $organizationId,
        public readonly int $batchId,
        public readonly string $target,
    ) {
        $this->onQueue('imports');
    }

    public function handle(OrganizationContext $context, DryRunner $runner): void
    {
        $organization = Organization::query()->find($this->organizationId);

        if ($organization === null) {
            return;
        }

        $context->run($organization, fn () => $this->commit($runner));
    }

    public function failed(?Throwable $exception): void
    {
        $organization = Organization::query()->find($this->organizationId);

        if ($organization === null) {
            return;
        }

        app(OrganizationContext::class)->run($organization, function (): void {
            DB::transaction(function (): void {
                $batch = ImportBatch::query()->whereKey($this->batchId)->lockForUpdate()->first();

                if ($batch === null || $batch->status !== ImportStatus::Committing) {
                    return;
                }

                $batch->status = ImportStatus::PartiallyFailed;
                $batch->failure_reason = __('The import stopped at row :row of the :kind sheet. Rows before it were saved; roll the import back or ask GoProperty support.', [
                    'row' => $batch->commit_cursor['row'] ?? '?',
                    'kind' => $this->target,
                ]);
                $batch->save();

                app(ImportBatches::class)->audit('import.failed', $batch, null, 'Import commit stopped', ['rows_done' => $batch->rows_done]);
            });
        });
    }

    private function commit(DryRunner $runner): void
    {
        $started = hrtime(true);
        $batch = ImportBatch::query()->find($this->batchId);

        if ($batch === null || $batch->status !== ImportStatus::Committing || ($batch->commit_cursor['target'] ?? null) !== $this->target) {
            return; // Done already, rolled back, or a repeated job.
        }

        $pairs = $runner->ordered($batch);
        $position = null;

        foreach ($pairs as $i => [$sheet]) {
            if ($sheet->target === $this->target) {
                $position = $i;
            }
        }

        [$sheet, $target] = $position !== null ? $pairs[$position] : [null, null];

        if (! $sheet instanceof SheetMapping || ! $target instanceof ImportTarget) {
            throw new RuntimeException("Import target {$this->target} is not registered.");
        }

        $media = $batch->file() ?? throw new RuntimeException('The uploaded file is gone.');
        $from = (int) ($batch->commit_cursor['row'] ?? $sheet->headerRow + 1);
        $budget = config('import.job_seconds', 30);
        $budget = is_int($budget) && $budget > 0 ? $budget : 30;
        $chunkSize = config('import.chunk_rows', 100);
        $chunkSize = is_int($chunkSize) && $chunkSize > 0 ? $chunkSize : 100;
        $run = ImportRun::commit($batch->organization_id, $batch->id);

        $finished = LocalCopy::using($media, function (string $path) use ($batch, $sheet, $target, $run, $from, $budget, $chunkSize, $started): bool {
            $chunk = [];
            $chunkStart = $from;

            foreach (SpreadsheetFile::open($path, $batch->file_type)->rows($sheet->index, $from) as $number => $cells) {
                $row = $sheet->row($number, $cells);

                if (! $row->isBlank()) {
                    $chunk[] = $row;
                }

                if (count($chunk) >= $chunkSize) {
                    if (! $this->write($target, $run, $chunk, $chunkStart, $number + 1)) {
                        return false;
                    }

                    [$chunk, $chunkStart] = [[], $number + 1];

                    if ((hrtime(true) - $started) / 1e9 >= $budget) {
                        // The rest of the sheet in a fresh job (resumes from the cursor).
                        self::dispatch($this->organizationId, $this->batchId, $this->target);

                        return false;
                    }
                }
            }

            return $this->write($target, $run, $chunk, $chunkStart, null);
        });

        if ($finished) {
            $this->nextSheet($pairs, (int) $position);
        }
    }

    /**
     * Writes one chunk and moves the cursor to `$next` (null: end of sheet),
     * in one transaction. False when the batch is no longer at
     * `$chunkStart` for this sheet (another run moved it): stop.
     *
     * @param  list<ImportRow>  $rows
     */
    private function write(ImportTarget $target, ImportRun $run, array $rows, int $chunkStart, ?int $next): bool
    {
        return DB::transaction(function () use ($target, $run, $rows, $chunkStart, $next): bool {
            $batch = ImportBatch::query()->whereKey($this->batchId)->lockForUpdate()->first();

            if ($batch === null || $batch->status !== ImportStatus::Committing
                || ($batch->commit_cursor['target'] ?? null) !== $this->target
                || (int) ($batch->commit_cursor['row'] ?? 0) !== $chunkStart) {
                return false;
            }

            $result = $batch->result_counts ?? [];
            $counts = $result['targets'][$this->target] ?? array_fill_keys(array_map(static fn (ImportAction $a): string => $a->value, ImportAction::cases()), 0);
            $skipped = is_array($result['skipped'] ?? null) ? $result['skipped'] : [];

            foreach ($rows as $row) {
                $errors = array_values(array_filter($target->validate($row, $run), static fn (RowIssue $issue): bool => $issue->isError()));
                $outcome = $errors === [] ? $target->upsert($row, $run) : null;
                $action = $outcome === null ? ImportAction::Skip : $outcome->action;
                $counts[$action->value] = ($counts[$action->value] ?? 0) + 1;

                if ($action === ImportAction::Skip && count($skipped) < self::SKIPPED_LISTED) {
                    $reason = $outcome === null ? $errors[0]->message : ($outcome->reason ?? __('Skipped'));
                    $skipped[] = ['target' => $this->target, 'row' => $row->number, 'reason' => $reason];
                }
            }

            $result['targets'][$this->target] = $counts;
            $result['skipped'] = $skipped;
            $batch->result_counts = $result;
            $batch->rows_done = min($batch->rows_total, $batch->rows_done + count($rows));
            $batch->commit_cursor = ['target' => $this->target, 'row' => $next ?? PHP_INT_MAX];
            $batch->save();

            return true;
        });
    }

    /** @param  list<array{SheetMapping, ImportTarget|null}>  $pairs */
    private function nextSheet(array $pairs, int $position): void
    {
        $next = $pairs[$position + 1] ?? null;
        $nextTarget = $next[1] ?? null;

        if ($next !== null && $nextTarget === null) {
            throw new RuntimeException("Import target {$next[0]->target} is not registered.");
        }

        $moved = DB::transaction(function () use ($next): bool {
            $batch = ImportBatch::query()->whereKey($this->batchId)->lockForUpdate()->first();

            if ($batch === null || $batch->status !== ImportStatus::Committing || ($batch->commit_cursor['target'] ?? null) !== $this->target) {
                return false;
            }

            if ($next !== null && $next[1] !== null) {
                $batch->commit_cursor = ['target' => $next[1]->key(), 'row' => $next[0]->headerRow + 1];
                $batch->save();

                return true;
            }

            $batch->status = ImportStatus::Committed;
            $batch->commit_cursor = null;
            $batch->rows_done = $batch->rows_total;
            $batch->save();

            app(ImportBatches::class)->audit('import.committed', $batch, null, 'Import committed', ['counts' => $batch->result_counts['targets'] ?? []]);

            // The setup checklist's "Your properties" item (ONBOARDING_COMPLIANCE.md § 2).
            app(OnboardingChecklist::class)->complete(ChecklistItem::Data, $batch->committedBy()->first(), ['import_batch_id' => $batch->id]);

            return false;
        });

        if ($moved && $nextTarget !== null) {
            self::dispatch($this->organizationId, $this->batchId, $nextTarget->key());
        }
    }
}
