<?php

declare(strict_types=1);

namespace Modules\OwnerStatements\Contracts;

/**
 * Whether the agency shares tenants' phone and email with an owner in the
 * owner portal (P08-05; product owner 2026-10-28: owners see tenant names
 * only unless the agency switches this on per owner). Answers for the
 * current organization only. Bound in OwnerStatementsServiceProvider.
 */
interface TenantContactSharing
{
    public function sharesWith(int $ownerProfileId): bool;
}
