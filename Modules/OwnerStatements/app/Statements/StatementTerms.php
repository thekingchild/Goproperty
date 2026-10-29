<?php

declare(strict_types=1);

namespace Modules\OwnerStatements\Statements;

/**
 * What the agency agreed with one owner, as statements use it (P08-05):
 * commission in basis points (null = not set) and whether utilities
 * collected pass to the owner. The defaults (no row yet): commission not
 * set, utilities kept by the agency's arrangement (not counted). Owners
 * always see their tenants' details (product owner 2026-10-29, P03-16:
 * the per-owner "share tenant contact details" switch is gone).
 */
final readonly class StatementTerms
{
    public function __construct(
        public ?int $commissionBps = null,
        public bool $utilitiesToOwner = false,
    ) {}
}
