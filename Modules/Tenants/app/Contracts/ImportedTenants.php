<?php

declare(strict_types=1);

namespace Modules\Tenants\Contracts;

use Modules\Import\Contracts\ImportRun;

/**
 * How another module's import sheet names a tenant (DATA_IMPORT.md § 1:
 * the Leases sheet, P03-13a): by phone number or by Your ID, the keys the
 * Tenants sheet matches on, without reading the Tenants module's tables
 * (ARCHITECTURE.md § 8 rule 3). During a dry run a tenant the same file
 * adds (Tenants sheet) is found too, as `inFile` with no summary yet;
 * during a commit that sheet is already saved. Answers for the current
 * organization only. Bound in TenantsServiceProvider.
 */
interface ImportedTenants
{
    public function resolve(string $reference, ImportRun $run): TenantReference;
}
