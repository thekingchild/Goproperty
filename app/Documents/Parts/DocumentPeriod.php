<?php

declare(strict_types=1);

namespace App\Documents\Parts;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * The period a bill or one of its lines covers, both days included, as the
 * billing module worked it out (Modules\Leasing\CycleCalculator; documents
 * only print it): "01/01/2027 – 31/12/2027".
 */
final readonly class DocumentPeriod
{
    public function __construct(
        public CarbonImmutable $start,
        public CarbonImmutable $end,
    ) {
        if ($end->lessThan($start->startOfDay())) {
            throw new InvalidArgumentException('A period ends on or after the day it starts.');
        }
    }

    public function label(): string
    {
        return DocumentDates::day($this->start).' – '.DocumentDates::day($this->end);
    }
}
