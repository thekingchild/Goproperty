<?php

declare(strict_types=1);

namespace App\Installer\Upgrades;

use Illuminate\Contracts\Foundation\Application;
use Throwable;

/**
 * Maintenance mode for an upgrade without SSH (INSTALLER.md § 10, step 4).
 * Laravel's own maintenance mode (the `storage/framework/down` file, so it
 * travels with storage/ to the new folder), marked as an upgrade: visitors
 * get the 503 page while the console stays open for staff, so the
 * super-admin can run the upgrade step and switch it off again
 * (App\Http\Middleware\PreventRequestsDuringMaintenance). `php artisan down`
 * stays as it was: it closes the console too, and only `php artisan up`
 * ends it.
 */
final readonly class UpgradeMaintenance
{
    /** In the maintenance payload: an upgrade's, the console stays open. */
    public const string MARKER = 'goproperty_upgrade';

    public function __construct(private Application $app) {}

    public function active(): bool
    {
        return $this->app->maintenanceMode()->active();
    }

    /** On, and switched on for an upgrade (console open). */
    public function forUpgrade(): bool
    {
        if (! $this->active()) {
            return false;
        }

        try {
            $data = $this->app->maintenanceMode()->data();
        } catch (Throwable) {
            return false;
        }

        return ($data[self::MARKER] ?? false) === true;
    }

    /** Turns it on for an upgrade; false when maintenance mode was already on. */
    public function start(): bool
    {
        if ($this->active()) {
            return false;
        }

        $this->app->maintenanceMode()->activate([
            'except' => [],
            'redirect' => null,
            'retry' => 60,
            'refresh' => null,
            'secret' => null,
            'status' => 503,
            'template' => null,
            self::MARKER => true,
        ]);

        return true;
    }

    /** Turns it off when it is an upgrade's; `php artisan down` is left alone. */
    public function end(): bool
    {
        if (! $this->forUpgrade()) {
            return false;
        }

        $this->app->maintenanceMode()->deactivate();

        return true;
    }
}
