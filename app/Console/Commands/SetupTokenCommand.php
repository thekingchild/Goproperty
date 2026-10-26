<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Installer\InstallationMarkers;
use App\Installer\SetupToken;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Unlock method A (INSTALLER.md § 4): prints a one-time token for the web
 * installer. Only someone with a shell on the host can run it.
 */
final class SetupTokenCommand extends Command
{
    protected $signature = 'goproperty:setup-token';

    protected $description = 'Print a one-time token that unlocks the web installer';

    public function handle(InstallationMarkers $markers, SetupToken $tokens): int
    {
        if (! $markers->installerOpen()) {
            $this->components->error('GoProperty is already installed, so the installer is closed. Recovery is from the command line (INSTALLER.md § 9).');

            return self::FAILURE;
        }

        $token = $tokens->issue();
        Log::channel('install')->info('Setup token issued');

        $this->newLine();
        $this->line('  '.$token);
        $this->newLine();
        $this->components->info('Paste this token on the /install page. It is shown only once; running this command again replaces it.');

        return self::SUCCESS;
    }
}
