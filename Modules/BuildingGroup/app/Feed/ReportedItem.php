<?php

declare(strict_types=1);

namespace Modules\BuildingGroup\Feed;

use Modules\BuildingGroup\Enums\PostStatus;
use Modules\BuildingGroup\Enums\ReactableType;

/** A post or comment with open reports, for the moderators' queue (P07-04). */
final readonly class ReportedItem
{
    /** @param  list<QueuedReport>  $reports  oldest first */
    public function __construct(
        public ReactableType $type,
        public int $id,
        public int $postId,
        public string $author,
        public string $excerpt,
        public PostStatus $status,
        public bool $fromTenant,
        public array $reports,
        public bool $mine = false,
    ) {}

    public function key(): string
    {
        return $this->type->value.':'.$this->id;
    }
}
