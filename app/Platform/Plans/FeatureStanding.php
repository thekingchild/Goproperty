<?php

declare(strict_types=1);

namespace App\Platform\Plans;

use App\Plans\Feature;
use App\Plans\FeatureValue;
use Carbon\CarbonImmutable;

/**
 * One feature for one organization on its console page (P06-07a;
 * docs/plans/P06-subtasks.md default 53): the plan's value (null before the
 * organization has a plan: not gated), the override in force, if any, and
 * what applies (App\Plans\Features: the override wins over the plan).
 */
final readonly class FeatureStanding
{
    public function __construct(
        public Feature $feature,
        public ?FeatureValue $plan,
        public FeatureValue $applies,
        public ?FeatureValue $override = null,
        public ?string $reason = null,
        public ?string $grantedBy = null,
        public ?CarbonImmutable $grantedAt = null,
        public ?CarbonImmutable $until = null,
    ) {}

    public function overridden(): bool
    {
        return $this->override !== null;
    }

    /**
     * The row for the console's table (Filament custom data).
     *
     * @return array<string, mixed>
     */
    public function toRow(): array
    {
        return [
            'key' => $this->feature->value,
            'feature' => $this->feature->label(),
            'is_limit' => $this->feature->isLimit(),
            'plan' => $this->plan?->describe($this->feature) ?? 'No plan yet (not gated)',
            'override' => $this->override?->describe($this->feature),
            'reason' => $this->reason,
            'granted_by' => $this->grantedBy,
            'granted_at' => $this->grantedAt,
            'until' => $this->until,
            'applies' => $this->applies->describe($this->feature),
        ];
    }
}
