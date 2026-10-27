<?php

declare(strict_types=1);

namespace Modules\Leasing\Providers;

use Modules\Leasing\Contracts\CycleCalculator as CycleCalculatorContract;
use Modules\Leasing\CycleCalculator;
use Nwidart\Modules\Support\ModuleServiceProvider;

/**
 * The Leasing module (ARCHITECTURE.md § 6.3, § 8). The base provider loads
 * the module's migrations; this one binds the cycle maths other modules use
 * through Contracts\CycleCalculator (AGENT.md § 3 rule 3). Leases, their
 * pages, routes and the sidebar item come with the lease engine (P03-03a).
 */
final class LeasingServiceProvider extends ModuleServiceProvider
{
    protected string $name = 'Leasing';

    protected string $nameLower = 'leasing';

    public function register(): void
    {
        parent::register();

        $this->app->singleton(CycleCalculatorContract::class, CycleCalculator::class);
    }
}
