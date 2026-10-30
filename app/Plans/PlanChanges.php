<?php

declare(strict_types=1);

namespace App\Plans;

use App\Marketing\Plan;
use App\Support\Money;

/**
 * What changes between two sets of a plan's terms, in words (P06-01a): for
 * the notice email, the workspace banner and the console.
 */
final class PlanChanges
{
    /** @return list<string> one line per change, "Base price: ₦35,000 → ₦40,000 a month" */
    public static function lines(Plan $before, Plan $after): array
    {
        $lines = [];

        if (! $before->base->equals($after->base)) {
            $lines[] = "Base price: {$before->base->format()} → {$after->base->format()} a month";
        }

        if ($before->includedUnits !== $after->includedUnits) {
            $lines[] = 'Units included: '.number_format($before->includedUnits).' → '.number_format($after->includedUnits);
        }

        if (! self::sameMoney($before->extraUnit, $after->extraUnit)) {
            $lines[] = 'Each extra unit: '.($before->extraUnit?->format() ?? 'custom').' → '.($after->extraUnit?->format() ?? 'custom').' a month';
        }

        foreach (Feature::cases() as $feature) {
            $old = $before->feature($feature);
            $new = $after->feature($feature);

            if (! $old->equals($new)) {
                $lines[] = "{$feature->label()}: {$old->describe($feature)} → {$new->describe($feature)}";
            }
        }

        return $lines;
    }

    private static function sameMoney(?Money $a, ?Money $b): bool
    {
        return $a === null || $b === null ? $a === $b : $a->equals($b);
    }
}
