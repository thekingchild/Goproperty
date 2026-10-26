<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Backup\ArchivePassword;
use Illuminate\Console\Command;

/**
 * Prints the backup zips' password, for opening a backup by hand when the
 * site is down (docs/runbooks/restore.md). Treat it like APP_KEY.
 */
final class BackupPasswordCommand extends Command
{
    protected $signature = 'goproperty:backup-password';

    protected $description = 'Print the password of the backup zips (keep it secret)';

    public function handle(ArchivePassword $password): int
    {
        $this->components->warn('Anyone with this password and a backup file can read every tenant\'s data.');
        $this->line($password->get());

        return self::SUCCESS;
    }
}
