<?php

declare(strict_types=1);

namespace Modules\Tenants\Providers;

use Illuminate\Support\Facades\Gate;
use Modules\Import\Contracts\ImportTargets;
use Modules\Tenants\Import\TenantsImportTarget;
use Modules\Tenants\Models\TenantProfile;
use Modules\Tenants\Policies\TenantProfilePolicy;
use Nwidart\Modules\Support\ModuleServiceProvider;

/**
 * The Tenants module (ARCHITECTURE.md § 8): tenant profiles. The base
 * provider loads the module's migrations; this one adds the policy and
 * makes tenants importable by registering TenantsImportTarget into the
 * Import module's registry (through its contract, AGENT.md § 4; nothing
 * happens when the Import module is off). Pages, routes and the sidebar
 * item come with the tenant screens (P03-01a).
 */
final class TenantsServiceProvider extends ModuleServiceProvider
{
    protected string $name = 'Tenants';

    protected string $nameLower = 'tenants';

    public function boot(): void
    {
        parent::boot();

        Gate::policy(TenantProfile::class, TenantProfilePolicy::class);

        $this->callAfterResolving(ImportTargets::class, static function (ImportTargets $targets): void {
            $targets->register(TenantsImportTarget::class);
        });
    }
}
