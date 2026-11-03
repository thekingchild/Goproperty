<?php

declare(strict_types=1);

namespace Modules\BuildingGroup\Feed;

use Carbon\CarbonImmutable;

/** A tenant muted in the group now, for its moderators (P07-04). */
final readonly class MutedMember
{
    public function __construct(
        public int $memberId,
        public string $name,
        public CarbonImmutable $until,
    ) {}
}
