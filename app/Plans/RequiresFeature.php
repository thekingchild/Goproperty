<?php

declare(strict_types=1);

namespace App\Plans;

use Attribute;

/**
 * Marks a controller action or Livewire method (or a whole component) that
 * only runs when the organization's plan, or an override, includes the
 * feature (P06-01b; ARCHITECTURE.md § 6.10). Enforced centrally by
 * PlanGate: the `plan` route middleware and the Livewire hook. The service
 * behind it checks again.
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_CLASS)]
final readonly class RequiresFeature
{
    public function __construct(public Feature $feature) {}
}
