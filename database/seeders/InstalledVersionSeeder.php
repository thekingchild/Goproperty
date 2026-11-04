<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Installer\AppVersion;
use App\Installer\InstallationRecord;
use App\Installer\InstallStage;
use Illuminate\Database\QueryException;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Last of the install seeders: on a finished installation, records the
 * release whose seeders just ran in `installation_state.version`, so
 * Console → Operations → Version can tell an upgrade whose seeders have not
 * run yet (INSTALLER.md § 10, P09-06). Whichever way the upgrade ran — the
 * Deploy workflow, `db:seed` after `migrate --force`, or the console's
 * upgrade step — the record follows.
 *
 * Nothing is written during an install (the installer records the version
 * itself at finish), without the row, or for code that has no release
 * number ("dev": a checkout or deploy archive). Idempotent: an unchanged
 * version writes nothing.
 */
final class InstalledVersionSeeder extends Seeder
{
    public function run(): void
    {
        $version = AppVersion::current();

        if (! AppVersion::isRelease($version)) {
            return;
        }

        try {
            DB::table(InstallationRecord::TABLE)
                ->where('id', 1)
                ->where('status', InstallStage::Finalized->value)
                ->where(fn ($query) => $query->whereNull('version')->orWhere('version', '!=', $version))
                ->update(['version' => $version, 'updated_at' => now()]);
        } catch (QueryException $exception) {
            if (! InstallationRecord::isMissingTable($exception)) {
                throw $exception;
            }
        }
    }
}
