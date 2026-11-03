<?php

declare(strict_types=1);

namespace Modules\BuildingGroup\Feed;

use Carbon\CarbonImmutable;
use Modules\BuildingGroup\Enums\ReportReason;

/** One member's open report, as the moderators' queue shows it (P07-04). */
final readonly class QueuedReport
{
    public function __construct(
        public ReportReason $reason,
        public string $reporter,
        public ?string $note,
        public ?CarbonImmutable $at,
    ) {}
}
