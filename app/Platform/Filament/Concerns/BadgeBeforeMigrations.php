<?php

declare(strict_types=1);

namespace App\Platform\Filament\Concerns;

use Closure;
use Illuminate\Database\QueryException;

/**
 * A navigation badge is drawn on every console page, the Version page and
 * the upgrade step included. Those run on the new release's code against
 * the previous release's database until its migrations have run
 * (INSTALLER.md § 10; docs/plans/P09-subtasks.md default 59), so a badge
 * that counts rows of a table the release adds shows nothing while that
 * table is missing, instead of failing the page.
 */
trait BadgeBeforeMigrations
{
    /** @param  Closure(): int  $count */
    protected static function countBadge(Closure $count): ?string
    {
        try {
            $value = $count();
        } catch (QueryException $exception) {
            // 42S02: base table or view not found (MySQL and MariaDB).
            if (($exception->errorInfo[0] ?? null) === '42S02') {
                return null;
            }

            throw $exception;
        }

        return $value > 0 ? (string) $value : null;
    }
}
