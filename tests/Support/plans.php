<?php

declare(strict_types=1);

use App\Marketing\Plan;
use App\Marketing\PlanCatalogue;
use App\Models\Organization;
use App\Models\Subscription;
use App\Plans\Feature;
use App\Plans\FeatureValue;
use App\Platform\Plans\PlanEditor;
use App\Platform\Plans\PlanTerms;
use App\Subscriptions\SubscriptionService;
use Carbon\CarbonImmutable;

/*
 * Helpers for the plan and subscription tests (P06-01). Need
 * tests/Support/compliance.php (inOrganization) loaded, as Pest.php does.
 */

function catalogue(): PlanCatalogue
{
    $catalogue = app(PlanCatalogue::class);
    $catalogue->flush();

    return $catalogue;
}

function planNamed(string $key, ?CarbonImmutable $on = null): Plan
{
    return catalogue()->find($key, $on) ?? throw new LogicException("No plan {$key}.");
}

/** A trial on the plan, ending in $trialDays days. */
function startTrial(Organization $organization, string $key = 'starter', int $trialDays = 14): Subscription
{
    return inOrganization($organization, fn (): Subscription => app(SubscriptionService::class)->start(null, planNamed($key), CarbonImmutable::now()->addDays($trialDays)));
}

/** An active subscription whose first billing period started today (its trial ended now). */
function activeSubscription(Organization $organization, string $key = 'starter'): Subscription
{
    return inOrganization($organization, function () use ($key): Subscription {
        $service = app(SubscriptionService::class);
        $service->start(null, planNamed($key), CarbonImmutable::now());

        return $service->endTrial();
    });
}

/**
 * The plan's terms today with some changed, for PlanEditor::schedule().
 *
 * @param  array<string, FeatureValue>  $features
 */
function termsLike(string $key, ?int $baseKobo = null, ?int $unitKobo = null, array $features = []): PlanTerms
{
    $plan = planNamed($key);
    $values = [];

    foreach (Feature::cases() as $feature) {
        $values[$feature->value] = $features[$feature->value] ?? $plan->feature($feature);
    }

    return new PlanTerms($baseKobo ?? $plan->base->kobo, $plan->includedUnits, $unitKobo ?? $plan->extraUnit?->kobo, $values);
}

function planEditor(): PlanEditor
{
    return app(PlanEditor::class);
}
