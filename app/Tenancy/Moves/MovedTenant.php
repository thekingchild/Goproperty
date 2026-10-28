<?php

declare(strict_types=1);

namespace App\Tenancy\Moves;

use Carbon\CarbonImmutable;

/**
 * A tenant record left behind when its tenant moved with their building
 * (MovedTenants): the organization they moved to (its name only) and when.
 */
final readonly class MovedTenant
{
    public function __construct(
        public int $tenantProfileId,
        public string $organizationName,
        public CarbonImmutable $movedAt,
        public int $handoverId,
    ) {}
}
