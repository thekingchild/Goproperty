<?php

declare(strict_types=1);

namespace App\Installer\Finishing;

/** Builds Laravel's config, route, view and event caches at finish (INSTALLER.md § 5). */
interface ApplicationOptimizer
{
    public function cache(): void;
}
