<?php

declare(strict_types=1);

namespace App\Installer\Web;

use App\Installer\Database\DatabaseSettings;
use App\Installer\InstallationMarkers;
use App\Installer\InstallStage;
use App\Installer\Migrations\BatchedMigrator;
use App\Models\PlatformStaff;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Session\Session;
use Throwable;

/**
 * Which wizard steps are done (INSTALLER.md § 5). Durable facts come from
 * the installation markers, Laravel's `migrations` table and the staff
 * table, so a lost session or a re-unlock carries on where the install
 * stopped. Choices that leave no trace (server check seen, email or storage
 * skipped) live in the installer session and are simply asked again.
 */
final class WizardProgress
{
    public const string SERVER = 'installer.server';

    public const string SEEDED = 'installer.seeded';

    public const string MAIL = 'installer.mail';

    public const string STORAGE = 'installer.storage';

    public const string ENROLMENT = 'installer.enrolment';

    public const string RECOVERY_CODES = 'installer.recovery_codes';

    public function __construct(
        private readonly InstallationMarkers $markers,
        private readonly BatchedMigrator $migrator,
        private readonly Config $config,
    ) {}

    /** The first step that is not done. */
    public function current(Session $session): WizardStep
    {
        foreach (WizardStep::cases() as $step) {
            if (! $this->isDone($step, $session)) {
                return $step;
            }
        }

        return WizardStep::Finish;
    }

    public function isDone(WizardStep $step, Session $session): bool
    {
        return match ($step) {
            WizardStep::Unlock => true,
            WizardStep::Server => $session->get(self::SERVER) === true || $this->reached(InstallStage::Configured),
            WizardStep::Site => $this->reached(InstallStage::Configured),
            WizardStep::Database => $this->reached(InstallStage::OwnerReady)
                || ($session->get(self::SEEDED) === true && $this->migrationsFinished()),
            WizardStep::Services => $this->reached(InstallStage::OwnerReady)
                || (in_array($session->get(self::MAIL), ['tested', 'skipped'], true)
                    && in_array($session->get(self::STORAGE), ['tested', 'skipped'], true)),
            WizardStep::Admin => $this->superAdminReady() && ! $session->has(self::RECOVERY_CODES),
            WizardStep::Finish => false,
        };
    }

    /**
     * The database settings in `.env` no longer match the ones the install
     * started with (INSTALLER.md § 6): the wizard stops and points at the
     * recovery steps instead of carrying on against another database.
     */
    public function settingsChanged(): bool
    {
        $checkpoint = $this->markers->checkpoint();

        if ($checkpoint->read()->fingerprint === null) {
            return false;
        }

        $name = $this->config->get('database.default');
        $connection = $this->config->get('database.connections.'.(is_string($name) ? $name : 'mysql'));

        try {
            return ! $checkpoint->matches(DatabaseSettings::fromConnection(is_array($connection) ? $connection : []));
        } catch (Throwable) {
            return true;
        }
    }

    public function reached(InstallStage $stage): bool
    {
        $current = $this->markers->checkpoint()->read()->stage;

        return $current !== null && $current->order() >= $stage->order();
    }

    /** The first staff account exists and has its authenticator app set up. */
    public function superAdminReady(): bool
    {
        try {
            $staff = PlatformStaff::query()->orderBy('id')->first();
        } catch (Throwable) {
            return false;
        }

        return $staff !== null && $staff->two_factor_secret !== null && $staff->two_factor_confirmed_at !== null;
    }

    private function migrationsFinished(): bool
    {
        try {
            return $this->migrator->progress()->finished();
        } catch (Throwable) {
            return false;
        }
    }
}
