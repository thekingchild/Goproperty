<?php

declare(strict_types=1);

namespace App\Http\Controllers\Console;

use App\Installer\Upgrades\UpgradeRefused;
use App\Installer\Upgrades\UpgradeStepKind;
use App\Installer\Upgrades\WebUpgrade;
use App\Models\PlatformStaff;
use App\Platform\Console\StepUp;
use App\Platform\Filament\Pages\SystemUpgrade;
use App\Platform\Staff\StaffPermission;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * The upgrade step's two posts (Console → Version → Run pending migrations,
 * INSTALLER.md § 10, P09-06), behind the console's sign-in, 2FA, CSRF and
 * `system.upgrade` (super-admins):
 *
 * - `confirm`: the password and authenticator code (StepUp, 10 minutes)
 *   and a tick that a database backup was taken before the new files went
 *   up;
 * - `run`: one piece of work (WebUpgrade::step()), only while the step-up
 *   is fresh. The result goes back to the page, which posts the next one
 *   by itself until the upgrade is done or something fails.
 */
final class UpgradeController
{
    /** The last step's result, flashed for the page. */
    public const string RESULT = 'console.upgrade.result';

    public function confirm(Request $request, StepUp $stepUp): RedirectResponse
    {
        $staff = $this->staff($request);

        $data = $request->validate([
            'password' => ['required', 'string', 'max:500'],
            'code' => ['nullable', 'string', 'max:20'],
            'backed_up' => ['accepted'],
        ], [
            'backed_up.accepted' => 'Tick that a database backup was taken before the new release\'s files went up.',
        ]);

        $problem = $stepUp->confirm($staff, (string) $data['password'], isset($data['code']) ? (string) $data['code'] : null);

        if ($problem !== null) {
            return redirect()->to(SystemUpgrade::getUrl(), 303)->withErrors(['password' => $problem]);
        }

        return redirect()->to(SystemUpgrade::getUrl(), 303)->with(self::RESULT, ['kind' => 'confirmed', 'message' => 'Confirmed. Starting the upgrade…', 'auto' => true]);
    }

    public function run(Request $request, WebUpgrade $upgrade, StepUp $stepUp): RedirectResponse
    {
        $staff = $this->staff($request);
        $back = redirect()->to(SystemUpgrade::getUrl(), 303);

        if (! $stepUp->isFresh()) {
            return $back->withErrors(['password' => 'Confirm it is you first: your password and authenticator code (they stay confirmed for '.StepUp::MINUTES.' minutes).']);
        }

        try {
            $step = $upgrade->step($staff);
        } catch (UpgradeRefused $refused) {
            return $back->with(self::RESULT, ['kind' => 'refused', 'message' => $refused->getMessage(), 'auto' => false]);
        }

        $progress = $step->state->migrations;

        return $back->with(self::RESULT, match ($step->kind) {
            UpgradeStepKind::Migrated => [
                'kind' => 'migrated', 'auto' => true,
                'message' => count($step->ran).' migration'.(count($step->ran) === 1 ? '' : 's').' ran ('.$progress->label().').'.($progress->finished() ? ' Next: the install seeders and caches.' : ''),
            ],
            UpgradeStepKind::MigrationFailed => [
                'kind' => 'failed', 'auto' => false, 'migration' => $step->failedMigration, 'error' => $step->error,
                'message' => 'Migration '.$step->failedMigration.' failed. The site stays in maintenance mode.',
            ],
            UpgradeStepKind::FinishFailed => [
                'kind' => 'failed', 'auto' => false, 'error' => $step->error,
                'message' => 'The install seeders or the caches failed. The site stays in maintenance mode.',
            ],
            UpgradeStepKind::Finished => [
                'kind' => 'finished', 'auto' => false,
                'message' => 'Upgraded to '.$step->state->codeVersion.'.'.($step->maintenanceEnded ? ' Maintenance mode is off: the site is open again.' : ''),
            ],
        });
    }

    private function staff(Request $request): PlatformStaff
    {
        Gate::authorize(StaffPermission::SystemUpgrade->value);
        $staff = $request->user('staff');
        abort_unless($staff instanceof PlatformStaff, 403);

        return $staff;
    }
}
