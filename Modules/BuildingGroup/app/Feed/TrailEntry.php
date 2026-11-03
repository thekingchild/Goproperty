<?php

declare(strict_types=1);

namespace Modules\BuildingGroup\Feed;

use Carbon\CarbonImmutable;
use Modules\BuildingGroup\Enums\ModerationAction;

/** One step of a post's or comment's moderation trail, as moderators see it (P07-04). */
final readonly class TrailEntry
{
    public function __construct(
        public ModerationAction $action,
        public string $actor,
        public ?CarbonImmutable $at,
        public ?string $reason,
    ) {}
}
