<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\PlatformStaff;
use App\Platform\Staff\StaffAudit;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Recovery (INSTALLER.md § 9, docs/runbooks/install-recovery.md): a staff
 * member who lost their authenticator app and their recovery codes cannot
 * open the console, and on a new install there may be no other super-admin
 * to help. Only someone with a shell on the host, or the hosting panel's
 * cron jobs, can run this; there is deliberately no web route for it.
 *
 * It clears the app secret and the recovery codes, so the next console
 * sign-in asks for the password and then sets up a new authenticator app
 * (2FA stays mandatory). Audited, log `staff`.
 */
final class StaffResetTwoFactorCommand extends Command
{
    protected $signature = 'goproperty:staff-reset-2fa
        {email : The staff member\'s work email}
        {--force : Do not ask first (for a one-time cron job on hosts without SSH)}';

    protected $description = 'Reset a staff member\'s two-factor sign-in, so the next console sign-in sets up a new authenticator app (recovery)';

    public function handle(StaffAudit $audit): int
    {
        $email = Str::lower(trim((string) $this->argument('email')));
        $staff = PlatformStaff::query()->whereRaw('lower(email) = ?', [$email])->first();

        if ($staff === null) {
            $this->components->error("No staff account has the email {$email}.");

            return self::FAILURE;
        }

        if (! $staff->hasTwoFactorEnabled() && $staff->two_factor_recovery_codes === null) {
            $this->components->info("{$staff->email} has no authenticator app set up: the next console sign-in sets one up.");

            return self::SUCCESS;
        }

        if (! $this->option('force')) {
            if (! $this->input->isInteractive()) {
                $this->components->error('Pass --force to reset without being asked (for example from a cron job).');

                return self::FAILURE;
            }

            if (! $this->components->confirm("Reset two-factor sign-in for {$staff->name} ({$staff->email})?")) {
                $this->components->warn('Nothing was changed.');

                return self::FAILURE;
            }
        }

        $staff->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
            // Ends "remember me" sign-ins made with the old authenticator.
            'remember_token' => Str::random(60),
        ])->save();

        $audit->record('staff.two_factor_reset', $staff, null, "two-factor sign-in reset for staff {$staff->email} from the command line", ['by' => 'cli']);

        $this->components->info("Two-factor sign-in is reset for {$staff->email}. At the next console sign-in, after the password, they set up a new authenticator app and get new recovery codes.");

        return self::SUCCESS;
    }
}
