<?php

declare(strict_types=1);

namespace App\Platform\Filament\Pages;

use App\Installer\Upgrades\LatestRelease;
use App\Installer\Upgrades\PendingUpgrade;
use App\Installer\Upgrades\UpgradeMaintenance;
use App\Installer\Upgrades\UpgradeState;
use App\Models\PlatformStaff;
use App\Platform\Console\StepUp;
use App\Platform\Filament\Resources\Backups\BackupResource;
use App\Platform\Operations\Health\BackupHealth;
use App\Platform\Operations\OperationsAudit;
use App\Platform\Staff\StaffPermission;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;
use UnitEnum;

/**
 * Console → Operations → Version (`/{ADMIN_PATH}/system/version`,
 * INSTALLER.md § 10 step 2, PLATFORM_CONSOLE.md § 2 "System → Version",
 * P09-06), for `system.status` (technical-admin, super-admin). Read-only:
 * the release running here, the newest published on GitHub (LatestRelease:
 * asked, never downloaded), migrations not run, whether the release's
 * install seeders have run, the last database and files backups, and
 * maintenance mode. There is no code download and no "update now": a stolen
 * console session must not be able to replace the code.
 *
 * With `system.upgrade` (super-admins) the page also links to the upgrade
 * step (SystemUpgrade) while something is pending, and switches maintenance
 * mode for an upgrade on and off (the console stays open while it is on;
 * password and authenticator code first, audited).
 */
final class SystemVersion extends Page
{
    protected static ?string $slug = 'system/version';

    protected static ?string $title = 'Version';

    protected static string|UnitEnum|null $navigationGroup = 'Operations';

    protected static ?int $navigationSort = 1;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTag;

    protected string $view = 'console.upgrades.version';

    public static function canAccess(): bool
    {
        return Gate::forUser(Filament::auth()->user())->allows(StaffPermission::SystemStatus->value);
    }

    public static function canUpgrade(): bool
    {
        return Gate::forUser(Filament::auth()->user())->allows(StaffPermission::SystemUpgrade->value);
    }

    public function getSubheading(): string
    {
        return 'Read-only. New releases are installed from the release zip (docs/runbooks/upgrade.md): nothing is ever downloaded from here.';
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $backups = app(BackupHealth::class);

        return [
            'state' => self::state(),
            'release' => app(LatestRelease::class)->check(),
            'repository' => app(LatestRelease::class)->repository(),
            'lastDatabaseBackup' => $backups->lastSuccess('db'),
            'lastFilesBackup' => $backups->lastSuccess('files'),
            'maintenance' => app(UpgradeMaintenance::class)->active(),
            'maintenanceForUpgrade' => app(UpgradeMaintenance::class)->forUpgrade(),
            'canUpgrade' => self::canUpgrade(),
            'upgradeUrl' => SystemUpgrade::getUrl(),
            'backupsUrl' => BackupResource::canViewAny() ? BackupResource::getUrl() : null,
        ];
    }

    /** Null when the database cannot be read. */
    public static function state(): ?UpgradeState
    {
        try {
            return app(PendingUpgrade::class)->state();
        } catch (Throwable $exception) {
            Log::warning('Console → Version could not read the database', ['error' => $exception->getMessage()]);

            return null;
        }
    }

    /** @return list<Action> */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('upgrade')
                ->label('Run pending migrations')
                ->color('primary')
                ->url(fn (): string => SystemUpgrade::getUrl())
                ->visible(fn (): bool => self::canUpgrade() && (self::state()?->pending() ?? false)),
            Action::make('checkAgain')
                ->label('Check GitHub again')
                ->color('gray')
                ->visible(fn (): bool => app(LatestRelease::class)->asks())
                ->action(function (): void {
                    abort_unless(self::canAccess(), 403);

                    if (! RateLimiter::attempt('console-release-check:'.$this->staff()->id, 5, static fn () => null, 600)) {
                        Notification::make()->title('Checked often enough: try again in a few minutes.')->warning()->send();

                        return;
                    }

                    app(LatestRelease::class)->forget();
                    Notification::make()->title('Asked GitHub again.')->success()->send();
                }),
            $this->stepUpAction('maintenanceOn', 'Turn on maintenance mode', 'Visitors see "down for maintenance" until it is turned off again; the console stays open for staff. Turn it on before you replace the files (docs/runbooks/upgrade.md).', function (): void {
                if (! app(UpgradeMaintenance::class)->start()) {
                    Notification::make()->title('Maintenance mode is already on.')->warning()->send();

                    return;
                }

                app(OperationsAudit::class)->record('upgrade.maintenance_on', 'Maintenance mode on for an upgrade', causer: $this->staff(), properties: ['by' => 'console']);
                Notification::make()->title('Maintenance mode is on. Visitors see the maintenance page; the console stays open.')->success()->send();
            })->visible(fn (): bool => self::canUpgrade() && ! app(UpgradeMaintenance::class)->active()),
            $this->stepUpAction('maintenanceOff', 'Turn off maintenance mode', 'The site opens to visitors again. If migrations are still pending, run them first.', function (): void {
                if (! app(UpgradeMaintenance::class)->end()) {
                    Notification::make()->title('Maintenance mode was not turned on here: end it with php artisan up.')->warning()->send();

                    return;
                }

                app(OperationsAudit::class)->record('upgrade.maintenance_off', 'Maintenance mode off', causer: $this->staff(), properties: ['by' => 'console', 'pending' => self::state()?->pending()]);
                Notification::make()->title('Maintenance mode is off.')->success()->send();
            })->visible(fn (): bool => self::canUpgrade() && app(UpgradeMaintenance::class)->forUpgrade()),
        ];
    }

    /** Password and authenticator code first (StepUp), then $run. */
    private function stepUpAction(string $name, string $label, string $description, callable $run): Action
    {
        $needs = static fn (): bool => ! app(StepUp::class)->isFresh();

        return Action::make($name)
            ->label($label)
            ->color('gray')
            ->requiresConfirmation()
            ->schema(fn (): array => $needs() ? array_filter([
                TextInput::make('password')->label('Your password')->password()->required(),
                app(StepUp::class)->needsCode($this->staff()) ? TextInput::make('code')->label('Code from your authenticator app')->required()->length(6) : null,
            ]) : [])
            ->modalHeading($label)
            ->modalDescription(fn (): string => $description.($needs() ? ' Confirm it is you: it stays confirmed for '.StepUp::MINUTES.' minutes.' : ''))
            ->modalSubmitActionLabel($label)
            ->action(function (array $data) use ($needs, $run): void {
                abort_unless(self::canUpgrade(), 403);

                if ($needs()) {
                    $problem = app(StepUp::class)->confirm($this->staff(), is_string($data['password'] ?? null) ? $data['password'] : '', is_string($data['code'] ?? null) ? $data['code'] : null);

                    if ($problem !== null) {
                        Notification::make()->title($problem)->danger()->send();

                        return;
                    }
                }

                $run();
            });
    }

    private function staff(): PlatformStaff
    {
        $staff = Filament::auth()->user();
        abort_unless($staff instanceof PlatformStaff, 403);

        return $staff;
    }
}
