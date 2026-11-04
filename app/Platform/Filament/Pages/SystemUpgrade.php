<?php

declare(strict_types=1);

namespace App\Platform\Filament\Pages;

use App\Http\Controllers\Console\UpgradeController;
use App\Installer\Upgrades\UpgradeMaintenance;
use App\Models\PlatformStaff;
use App\Platform\Console\StepUp;
use App\Platform\Operations\Health\BackupHealth;
use App\Platform\Staff\StaffPermission;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Gate;

/**
 * Console → Operations → Version → Run pending migrations
 * (`/{ADMIN_PATH}/system/upgrade`, INSTALLER.md § 10 step 6, P09-06): the
 * upgrade step for hosts without SSH, for `system.upgrade` (super-admins).
 * The super-admin confirms their password and authenticator code and that
 * a backup was taken, then the page posts one piece of work per request to
 * UpgradeController — a batch of migrations, then the install seeders and
 * the caches — with the installer's progress bar, and carries on by itself.
 * Plain forms, so it needs no script to work and each request is short.
 */
final class SystemUpgrade extends Page
{
    protected static ?string $slug = 'system/upgrade';

    protected static ?string $title = 'Run pending migrations';

    protected static bool $shouldRegisterNavigation = false;

    protected string $view = 'console.upgrades.upgrade';

    public static function canAccess(): bool
    {
        return Gate::forUser(Filament::auth()->user())->allows(StaffPermission::SystemUpgrade->value);
    }

    public function getSubheading(): string
    {
        return 'For super-admins, after the new release\'s files are in place (docs/runbooks/upgrade.md). Each step is one short request; keep this tab open until it says the upgrade is done.';
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $staff = Filament::auth()->user();
        $stepUp = app(StepUp::class);
        $result = session(UpgradeController::RESULT);

        return [
            'state' => SystemVersion::state(),
            'fresh' => $stepUp->isFresh(),
            'needsCode' => $staff instanceof PlatformStaff && $stepUp->needsCode($staff),
            'lastDatabaseBackup' => app(BackupHealth::class)->lastSuccess('db'),
            'maintenance' => app(UpgradeMaintenance::class)->active(),
            'maintenanceForUpgrade' => app(UpgradeMaintenance::class)->forUpgrade(),
            'result' => is_array($result) ? $result : null,
            'versionUrl' => SystemVersion::getUrl(),
            'confirmUrl' => route('filament.console.system-upgrade.confirm'),
            'runUrl' => route('filament.console.system-upgrade.run'),
        ];
    }
}
