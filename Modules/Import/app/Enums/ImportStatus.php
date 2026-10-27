<?php

declare(strict_types=1);

namespace Modules\Import\Enums;

use App\Enums\Concerns\HasValues;

/**
 * An import batch's life (DATABASE_SCHEMA.md § 8A): uploaded → dry run
 * (failed or passed; repeatable after changing the mapping) → committing
 * (queued, one job per sheet) → committed, or partially_failed when a
 * commit job gave up → rolled_back within the rollback window. Changed
 * only by Modules\Import\Batches\ImportBatches and the commit job.
 */
enum ImportStatus: string
{
    use HasValues;

    case Uploaded = 'uploaded';
    case DryRunFailed = 'dry_run_failed';
    case DryRunPassed = 'dry_run_passed';
    case Committing = 'committing';
    case Committed = 'committed';
    case PartiallyFailed = 'partially_failed';
    case RolledBack = 'rolled_back';

    public function label(): string
    {
        return match ($this) {
            self::Uploaded => 'Uploaded',
            self::DryRunFailed => 'Dry run found errors',
            self::DryRunPassed => 'Ready to commit',
            self::Committing => 'Importing',
            self::Committed => 'Imported',
            self::PartiallyFailed => 'Stopped part-way',
            self::RolledBack => 'Rolled back',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Uploaded => 'zinc',
            self::DryRunFailed, self::PartiallyFailed => 'red',
            self::DryRunPassed => 'blue',
            self::Committing => 'amber',
            self::Committed => 'green',
            self::RolledBack => 'zinc',
        };
    }

    /** Before the commit: the mapping can change and the dry run can run again. */
    public function isPreparing(): bool
    {
        return in_array($this, [self::Uploaded, self::DryRunFailed, self::DryRunPassed], true);
    }

    /** Records were written (all of them, or some before a job gave up). */
    public function hasCommitted(): bool
    {
        return in_array($this, [self::Committed, self::PartiallyFailed], true);
    }
}
