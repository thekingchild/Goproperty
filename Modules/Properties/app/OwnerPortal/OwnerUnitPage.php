<?php

declare(strict_types=1);

namespace Modules\Properties\OwnerPortal;

/**
 * One of the owner's units with its lease history (P03-16): every lease
 * the agency activated on it, newest first, each with its tenant and its
 * timeline (moved in, renewed, rent changed, ended early, moved out).
 * Drafts never.
 */
final readonly class OwnerUnitPage
{
    /** @param  list<OwnerUnitLease>  $leases  newest first */
    public function __construct(
        public OwnerAgency $agency,
        public int $buildingId,
        public string $building,
        public OwnerUnit $unit,
        public array $leases,
    ) {}
}
