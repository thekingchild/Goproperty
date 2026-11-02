<?php

declare(strict_types=1);

namespace Modules\BuildingGroup\Portal;

use Carbon\CarbonImmutable;

/** A group the signed-in tenant is a member of, as the portal's list shows it. */
final readonly class PortalGroup
{
    public function __construct(
        public int $id,
        public string $name,
        public int $organizationId,
        public string $agency,
        public string $colour,
        public string $initials,
        public bool $readOnly,
        public int $posts,
        public ?CarbonImmutable $latestActivity,
    ) {}
}
