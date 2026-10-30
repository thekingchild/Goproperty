<?php

declare(strict_types=1);

namespace App\Plans;

use Attribute;

/**
 * Marks a controller action or Livewire method that adds one to a plan
 * limit (P06-01b): refused by PlanGate when the organization already uses
 * the whole limit (inviting a fourth staff user on Starter). The service
 * behind it checks again under its lock.
 */
#[Attribute(Attribute::TARGET_METHOD)]
final readonly class CountsAgainstLimit
{
    public function __construct(public Feature $feature) {}
}
