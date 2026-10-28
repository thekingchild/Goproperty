<?php

declare(strict_types=1);

namespace Modules\OwnerStatements\Statements;

/**
 * What the agency agreed with one owner, as statements use it (P08-05):
 * commission in basis points (null = not set), whether utilities collected
 * pass to the owner, whether the owner sees tenants' phone and email.
 * The defaults (no row yet): commission not set, utilities kept by the
 * agency's arrangement (not counted), tenant names only.
 */
final readonly class StatementTerms
{
    public function __construct(
        public ?int $commissionBps = null,
        public bool $utilitiesToOwner = false,
        public bool $shareTenantContacts = false,
    ) {}
}
