<?php

declare(strict_types=1);

namespace Modules\Properties\OwnerPortal;

use Modules\Leasing\Contracts\LeaseSummary;

/**
 * A lease of one of the owner's units as the owner portal shows it
 * (P03-15): the lease's terms, the unit and building, and the tenant's
 * name, phone and email (owners see their tenants in full since P03-16;
 * the rest of the tenant's details are on the tenant's page, the ID number
 * masked).
 */
final readonly class OwnerLease
{
    public function __construct(
        public LeaseSummary $lease,
        public string $tenantName,
        public string $unit,
        public string $building,
        public ?string $tenantPhone = null,
        public ?string $tenantEmail = null,
    ) {}

    public function statusColor(): string
    {
        return self::colorOf($this->lease->status);
    }

    /** Flux badge colour of a `leases.status` value. */
    public static function colorOf(string $status): string
    {
        return match ($status) {
            'active' => 'green',
            'terminated' => 'red',
            'renewed' => 'indigo',
            default => 'zinc',
        };
    }
}
