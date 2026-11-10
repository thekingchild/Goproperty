<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Organization;
use App\Models\User;
use Database\Seeders\DemoOrganizationSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Creates GoProperty's demo organization for staff walkthroughs (P09-05;
 * BILLING_PAYMENTS.md § 1.4, docs/runbooks/shared-hosting.md § 5): a small
 * agency with six months of made-up history (DemoOrganizationSeeder),
 * marked `is_demo`, so it is never billed, sends no SMS and stays out of
 * the console's analytics. Its owner is a new account on a staff mailbox.
 *
 * Once only: with a demo organization already there it says where and
 * changes nothing. The password is shown once on a terminal; from a cron
 * job (no terminal) it is not printed, and the owner sets one with
 * "Forgot password" instead.
 */
final class DemoOrganizationCommand extends Command
{
    protected $signature = 'goproperty:demo-organization
        {email : The owner\'s sign-in, a GoProperty staff mailbox (e.g. demo@goproperty.ng)}
        {--force : Do not ask first (for a one-time cron job on hosts without SSH)}';

    protected $description = 'Create GoProperty\'s demo organization for staff walkthroughs (never billed, sends no SMS)';

    public function handle(): int
    {
        $existing = Organization::query()->where('is_demo', true)->first();

        if ($existing !== null) {
            $this->components->info("The demo organization already exists: {$existing->name}, /o/{$existing->slug}. Nothing was changed.");

            return self::SUCCESS;
        }

        $email = Str::lower(trim((string) $this->argument('email')));

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $this->components->error("{$email} is not an email address.");

            return self::FAILURE;
        }

        if (User::query()->whereRaw('lower(email) = ?', [$email])->exists()) {
            $this->components->error("{$email} already has an account. Use a mailbox of its own for the demo owner.");

            return self::FAILURE;
        }

        if (Organization::query()->withTrashed()->where('slug', DemoOrganizationSeeder::SLUG)->exists()) {
            $this->components->error('Another organization already uses /o/'.DemoOrganizationSeeder::SLUG.'.');

            return self::FAILURE;
        }

        if (! $this->option('force')) {
            if (! $this->input->isInteractive()) {
                $this->components->error('Pass --force to create it without being asked (for example from a cron job).');

                return self::FAILURE;
            }

            if (! $this->components->confirm('Create '.DemoOrganizationSeeder::NAME." with made-up buildings, tenants and six months of rent, owned by {$email}?")) {
                $this->components->warn('Nothing was changed.');

                return self::FAILURE;
            }
        }

        $password = Str::password(24, symbols: false);
        $this->components->task('Creating '.DemoOrganizationSeeder::NAME, function () use ($email, $password): void {
            $seeder = app(DemoOrganizationSeeder::class)->forOwner($email, $password);
            $seeder->setContainer($this->laravel);
            $seeder->__invoke();
        });

        $this->components->info('Done: /o/'.DemoOrganizationSeeder::SLUG.". Sign in as {$email}.");

        if ($this->input->isInteractive()) {
            $this->components->twoColumnDetail('Password (shown once)', $password);
        } else {
            $this->components->warn('The password is not printed here: open the sign-in page, choose "Forgot password" and set one from the email.');
        }

        return self::SUCCESS;
    }
}
