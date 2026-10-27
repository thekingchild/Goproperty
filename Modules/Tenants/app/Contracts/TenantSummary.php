<?php

declare(strict_types=1);

namespace Modules\Tenants\Contracts;

/**
 * A tenant as other modules see it (TenantDirectory): plain values, no
 * model and never the ID number. `archived` is true for an archived
 * (soft-deleted) tenant; `phone` is E.164.
 */
final readonly class TenantSummary
{
    public function __construct(
        public int $id,
        public int $organizationId,
        public string $name,
        public string $phone,
        public ?string $email,
        public bool $archived,
        public bool $hasPortalAccess,
    ) {}
}
