<?php

declare(strict_types=1);

namespace Modules\Properties\OwnerPortal;

use Modules\Leasing\Contracts\LeaseSummary;

/**
 * A lease of one of the owner's units as the owner portal shows it
 * (P03-15): the lease's terms, the unit and building, and the tenant's
 * name only — never their phone number, email address, ID or next of kin
 * (the owner's view of a tenant is minimal; ARCHITECTURE.md § 5 "Owner
 * portal").
 */
final readonly class OwnerLease
{
    public function __construct(
        public LeaseSummary $lease,
        public string $tenantName,
        public string $unit,
        public string $building,
    ) {}

    public function statusColor(): string
    {
        return match ($this->lease->status) {
            'active' => 'green',
            'terminated' => 'red',
            'renewed' => 'indigo',
            default => 'zinc',
        };
    }
}
