<?php

declare(strict_types=1);

namespace Modules\Import\Jobs;

use App\Models\Organization;
use App\Tenancy\OrganizationContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Modules\Import\Batches\ImportBatches;
use Modules\Import\Enums\ImportStatus;
use Modules\Import\Models\ImportBatch;

/**
 * Deletes uploaded import files once they are 30 days old (DATA_IMPORT.md
 * § 4; config import.keep_uploads_days): the media row and its file go,
 * the batch keeps its report and counts and records `file_purged_at`.
 * Scheduled daily (Schedule::job, the `imports` queue). Works organization
 * by organization inside each one's context (no scope is removed), and
 * queues itself again when it runs out of its time budget. Safe to run
 * again: a purged batch has no file left to purge. A batch still
 * committing keeps its file.
 */
final class PurgeImportUploads implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $timeout = 50;

    public int $tries = 3;

    public int $backoff = 60;

    public function __construct()
    {
        $this->onQueue('imports');
    }

    public function handle(OrganizationContext $context, ImportBatches $batches): void
    {
        $started = hrtime(true);
        $budget = config('import.job_seconds', 30);
        $budget = is_int($budget) && $budget > 0 ? $budget : 30;
        $days = config('import.keep_uploads_days', 30);
        $cutoff = now()->subDays(is_int($days) && $days > 0 ? $days : 30);
        $outOfTime = static fn (): bool => (hrtime(true) - $started) / 1e9 >= $budget;
        $more = false;

        Organization::query()->orderBy('id')->chunkById(200, function (Collection $organizations) use ($context, $batches, $cutoff, $outOfTime, &$more): bool {
            foreach ($organizations as $organization) {
                $context->run($organization, function () use ($batches, $cutoff, $outOfTime, &$more): void {
                    $due = ImportBatch::query()
                        ->whereNotNull('media_id')
                        ->where('created_at', '<=', $cutoff)
                        ->where('status', '!=', ImportStatus::Committing->value)
                        ->orderBy('id')
                        ->get();

                    foreach ($due as $batch) {
                        if ($outOfTime()) {
                            $more = true;

                            return;
                        }

                        $this->purge($batch, $batches);
                    }
                });

                if ($more) {
                    return false;
                }
            }

            return true;
        });

        if ($more) {
            self::dispatch();
        }
    }

    private function purge(ImportBatch $batch, ImportBatches $batches): void
    {
        DB::transaction(function () use ($batch, $batches): void {
            $batch->file()?->delete();

            $batch->media_id = null;
            $batch->file_purged_at = now();
            $batch->save();

            $batches->audit('import.file_purged', $batch, null, 'Import file deleted after the retention period');
        });
    }
}
