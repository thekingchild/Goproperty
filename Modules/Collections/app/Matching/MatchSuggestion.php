<?php

declare(strict_types=1);

namespace Modules\Collections\Matching;

use Modules\Collections\Enums\MatchConfidence;

/** What Matching\MatchFinder found for a credit: the tenant, the bill (if one may take money) and how sure it is. */
final readonly class MatchSuggestion
{
    public function __construct(
        public MatchConfidence $confidence,
        public int $tenantProfileId,
        public ?int $billId,
    ) {}
}
