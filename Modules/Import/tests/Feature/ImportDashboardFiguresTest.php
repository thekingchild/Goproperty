<?php

declare(strict_types=1);

use Modules\Import\Enums\ImportStatus;
use Modules\Import\Models\ImportBatch;

/*
 * P08-04: an import writes and rolls back rows in bulk (no model events),
 * so its batch's save tells the dashboards — but only once rows can have
 * changed: a dry run writes nothing (ImportDryRunTest), not even the cache.
 */

it('tells the dashboards only when an import writes or rolls back rows', function (ImportStatus $status, bool $changes) {
    $batch = new ImportBatch;
    $batch->status = $status;

    expect($batch->changesDashboardFigures())->toBe($changes);
})->with([
    [ImportStatus::Uploaded, false],
    [ImportStatus::DryRunFailed, false],
    [ImportStatus::DryRunPassed, false],
    [ImportStatus::Committing, true],
    [ImportStatus::Committed, true],
    [ImportStatus::PartiallyFailed, true],
    [ImportStatus::RolledBack, true],
]);
