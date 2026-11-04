<?php

declare(strict_types=1);

namespace App\Platform\Operations\Alerts;

use Carbon\CarbonImmutable;

/** One alert rule's check: which of its subjects fail at `$now`, and what to say when one clears. */
interface AlertCheck
{
    public function rule(): AlertRule;

    /** @return list<AlertCondition> */
    public function failing(CarbonImmutable $now, AlertSettings $settings): array;

    /** One sentence for the "cleared" notice of an incident this check no longer finds. */
    public function cleared(OperationsAlert $incident, CarbonImmutable $now): string;
}
