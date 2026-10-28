<?php

declare(strict_types=1);

namespace Modules\Tenants\Contracts;

/**
 * A tenant named on an import sheet (ImportedTenants): the tenant on file,
 * or `inFile` for one the same file adds, or an `error` for the agency
 * (unknown, archived). `key` is the same for every row naming the same
 * tenant the same way; `name` is the tenant's name, or the reference as
 * written for a tenant the file adds.
 */
final readonly class TenantReference
{
    public function __construct(
        public ?TenantSummary $tenant,
        public bool $inFile,
        public ?string $key,
        public ?string $name,
        public ?string $error,
    ) {}

    public static function refused(string $error): self
    {
        return new self(null, false, null, null, $error);
    }
}
