<?php

declare(strict_types=1);

namespace App\Privacy\Breaches;

use App\Enums\Concerns\HasValues;

/**
 * How likely the breach is to harm people (NDPA 2023 s. 40): a risk to their
 * rights means the Commission is told within 72 hours; a high risk means
 * the people are told too, without delay.
 */
enum BreachRisk: string
{
    use HasValues;

    case Unlikely = 'unlikely';
    case Risk = 'risk';
    case High = 'high';

    public function label(): string
    {
        return match ($this) {
            self::Unlikely => 'Unlikely to harm anyone',
            self::Risk => 'A risk to people\'s rights',
            self::High => 'A high risk to people',
        };
    }
}
