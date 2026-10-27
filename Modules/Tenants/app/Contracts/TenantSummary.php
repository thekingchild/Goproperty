<?php

declare(strict_types=1);

namespace Modules\Tenants\Contracts;

/**
 * A tenant as other modules see it (TenantDirectory): plain values, no
 * model and never the ID number. `archived` is true for an archived
 * (soft-deleted) tenant; `phone` is E.164. `userId` is the tenant's portal
 * account (`tenant_profiles.user_id`), null without portal access: modules
 * that tell the tenant something in the app notify that user (P03-10b).
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
        public ?int $userId = null,
    ) {}
}
