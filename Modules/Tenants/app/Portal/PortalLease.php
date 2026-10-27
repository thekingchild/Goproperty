<?php

declare(strict_types=1);

namespace Modules\Tenants\Portal;

use Modules\Leasing\Contracts\LeaseSummary;

/**
 * A lease in the tenant portal (P03-11a): the Leasing module's summary with
 * the home it is for, named through the Properties module's directories.
 * `building` and `unit` fall back to plain words when the unit has since
 * been archived.
 */
final readonly class PortalLease
{
    public function __construct(
        public LeaseSummary $lease,
        public string $building,
        public string $unit,
        public ?string $address,
    ) {}

    public function isActive(): bool
    {
        return $this->lease->status === 'active';
    }

    /** Flux badge colour of the lease's status. */
    public function statusColor(): string
    {
        return match ($this->lease->status) {
            'active' => 'green',
            'renewed' => 'blue',
            'expired', 'terminated' => 'zinc',
            default => 'amber',
        };
    }
}
