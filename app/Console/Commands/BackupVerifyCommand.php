<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Backup\BackupVerifier;
use Illuminate\Console\Command;

/**
 * Restores the newest database backup into the verify database and checks
 * it (PLATFORM_CONSOLE.md § 4; monthly in routes/console.php).
 */
final class BackupVerifyCommand extends Command
{
    protected $signature = 'goproperty:backup-verify';

    protected $description = 'Restore the newest database backup into the verify database and check it';

    public function handle(BackupVerifier $verifier): int
    {
        $result = $verifier->verify();

        $result['ok'] ? $this->components->info($result['message']) : $this->components->error($result['message']);

        return $result['ok'] ? self::SUCCESS : self::FAILURE;
    }
}
