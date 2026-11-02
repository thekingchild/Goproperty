<?php

declare(strict_types=1);

namespace Modules\BuildingGroup\Feed;

/** One image of a post, as the feed shows it: short-lived signed links to its thumbnail and its large version. */
final readonly class FeedImage
{
    public function __construct(
        public string $thumbUrl,
        public string $fullUrl,
    ) {}
}
