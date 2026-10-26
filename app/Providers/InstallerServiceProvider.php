<?php

declare(strict_types=1);

namespace App\Providers;

use App\Installer\EnvironmentFile;
use App\Installer\Finishing\ApplicationOptimizer;
use App\Installer\Finishing\ArtisanOptimizer;
use App\Installer\Requirements\PhpServerEnvironment;
use App\Installer\Requirements\ServerEnvironment;
use Illuminate\Support\ServiceProvider;

/** Installer services (INSTALLER.md). */
final class InstallerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(ServerEnvironment::class, PhpServerEnvironment::class);
        $this->app->bind(ApplicationOptimizer::class, ArtisanOptimizer::class);
        $this->app->bind(EnvironmentFile::class, fn (): EnvironmentFile => EnvironmentFile::forApplication());
    }
}
