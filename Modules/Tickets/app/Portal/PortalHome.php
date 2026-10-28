<?php

declare(strict_types=1);

namespace Modules\Tickets\Portal;

/**
 * A home the tenant may report a problem for (TenantTickets::homes): a
 * live (draft or active) lease of theirs, with the unit, building and
 * agency names. `key` is what the form sends: "{organization id}-{lease id}".
 */
final readonly class PortalHome
{
    public function __construct(
        public string $key,
        public int $organizationId,
        public int $leaseId,
        public string $unit,
        public string $building,
        public string $agency,
    ) {}

    public function label(): string
    {
        return $this->unit.' · '.$this->building;
    }
}
