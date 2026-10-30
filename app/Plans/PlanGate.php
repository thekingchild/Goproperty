<?php

declare(strict_types=1);

namespace App\Plans;

use App\Models\Organization;
use App\Tenancy\OrganizationContext;
use ReflectionClass;
use ReflectionMethod;

/**
 * The one place that refuses what an organization's plan does not include
 * (P06-01b; ARCHITECTURE.md § 6.10, BILLING_PAYMENTS.md § 1.2):
 *
 * - routes: the `plan` middleware (App\Http\Middleware\EnsurePlanAllows),
 *   `plan:own_paystack`, and #[RequiresFeature] / #[CountsAgainstLimit] on
 *   the controller action;
 * - Livewire: App\Plans\Livewire\RefuseLivewireCallsOutsidePlan reads the
 *   same attributes on the component and the called method;
 * - services call ensureFeature() / ensureRoomFor() again, inside their lock.
 *
 * Refusals are PlanLimitReached (403) with a message that names the plan to
 * move to. Outside an organization nothing is refused here.
 */
final readonly class PlanGate
{
    public function __construct(
        private Features $features,
        private PlanUsage $usage,
        private OrganizationContext $context,
    ) {}

    /** @throws PlanLimitReached */
    public function ensureFeature(Feature $feature, ?Organization $organization = null): void
    {
        $organization ??= $this->organization();

        if ($organization !== null && ! $this->features->enabled($feature, $organization)) {
            throw new PlanLimitReached($this->featureMessage($feature, $organization));
        }
    }

    /**
     * Refuses one more of a counted limit when the organization already uses
     * all of it. $used: the count to judge (default: PlanUsage's).
     *
     * @throws PlanLimitReached
     */
    public function ensureRoomFor(Feature $feature, ?Organization $organization = null, ?int $used = null): void
    {
        $organization ??= $this->organization();

        if ($organization === null) {
            return;
        }

        $value = $this->features->value($feature, $organization);

        if ($value->limit === null) {
            return;
        }

        $used ??= $this->usage->count($feature, $organization);

        if (! $value->allowsAnother($used)) {
            throw new PlanLimitReached($this->limitMessage($feature, $organization, $value->limit, $used));
        }
    }

    /** Checks the attributes of a controller action or Livewire method (and its class). */
    public function ensureCallAllowed(string $class, string $method): void
    {
        if ($this->organization() === null || ! class_exists($class)) {
            return;
        }

        $targets = [new ReflectionClass($class)];

        if (method_exists($class, $method)) {
            $targets[] = new ReflectionMethod($class, $method);
        }

        foreach ($targets as $target) {
            foreach ($target->getAttributes(RequiresFeature::class) as $attribute) {
                $this->ensureFeature($attribute->newInstance()->feature);
            }

            foreach ($target->getAttributes(CountsAgainstLimit::class) as $attribute) {
                $this->ensureRoomFor($attribute->newInstance()->feature);
            }
        }
    }

    public function featureMessage(Feature $feature, Organization $organization): string
    {
        $current = $this->features->planFor($organization);
        $lowest = $this->features->lowestPlanWith($feature);

        $where = $lowest !== null ? "is on the {$lowest->name} plan and above" : 'is not on your plan';
        $now = $current !== null ? " {$organization->name} is on {$current->name}." : '';

        return "{$feature->shortLabel()} {$where}.{$now} An owner can change plan in Settings → Subscription.";
    }

    public function limitMessage(Feature $feature, Organization $organization, int $limit, int $used): string
    {
        $current = $this->features->planFor($organization);
        $bigger = $this->features->lowestPlanAllowing($feature, $used + 1);
        $planName = $current !== null ? "The {$current->name} plan" : 'Your plan';
        $what = $feature === Feature::StaffUsers ? 'staff users' : mb_strtolower($feature->shortLabel());
        $counted = $feature === Feature::StaffUsers ? ' (members and invitations still waiting)' : '';
        $move = $bigger !== null && $bigger->key !== $current?->key ? " Move to {$bigger->name} for more, or" : '';

        return "{$planName} includes {$limit} {$what} and {$organization->name} has {$used}{$counted}.{$move} remove one first.";
    }

    private function organization(): ?Organization
    {
        return $this->context->has() ? $this->context->get() : null;
    }
}
