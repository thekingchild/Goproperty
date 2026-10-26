<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Enums\ChecklistItem;
use App\Enums\OnboardingStep;
use App\Models\Membership;
use App\Models\OnboardingChecklistItem;
use App\Models\OrganizationOnboarding;
use App\Models\User;
use Closure;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Every organization-owned model (BelongsToOrganization) and how to make one
 * in the current organization, for the cross-tenant probes in
 * tests/Feature/Tenancy/CrossTenantAccessTest.php (IMPLEMENTATION_PLAN.md
 * P01 exit: cross-tenant tests cover every Phase-1 model).
 *
 * A task that adds an organization-owned model adds it here; the probe test
 * fails while any model in app/ or Modules/ is missing.
 */
final class OrganizationScopedModels
{
    /** @return array<class-string<Model>, Closure(): Model> */
    public static function factories(): array
    {
        return [
            Membership::class => fn (): Model => Membership::query()->create(['user_id' => User::factory()->create()->id]),
            OrganizationOnboarding::class => fn (): Model => OrganizationOnboarding::query()->create(['step' => OnboardingStep::Tier]),
            OnboardingChecklistItem::class => fn (): Model => OnboardingChecklistItem::query()->create(['item' => ChecklistItem::Plan]),
        ];
    }

    /** @param  class-string<Model>  $class */
    public static function make(string $class): Model
    {
        $factory = self::factories()[$class] ?? throw new LogicException("{$class} is not in ".self::class.'.');

        return $factory();
    }

    /** @return array<string, array{class-string<Model>}> */
    public static function dataset(): array
    {
        $dataset = [];

        foreach (array_keys(self::factories()) as $class) {
            $dataset[class_basename($class)] = [$class];
        }

        return $dataset;
    }
}
