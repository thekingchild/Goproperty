<?php

declare(strict_types=1);

namespace App\Plans;

use Carbon\CarbonImmutable;

/**
 * Features::value() answers kept for one request or queued job (scoped in
 * AppServiceProvider), by organization, feature and Lagos day (the plan's
 * terms are the ones in effect on a date): a page asks the same
 * features many times (every Building Group ability asks whether the plan
 * has the group, P09-03), and each answer read an override and the
 * subscription. Forgotten when a subscription, a feature override or an
 * organization is saved or deleted, so a change is seen at once in the
 * same request.
 *
 * @internal
 */
final class FeatureLookups
{
    /** @var array<string, FeatureValue> "organization id:feature:day" => value */
    private array $values = [];

    public function get(int $organizationId, Feature $feature): ?FeatureValue
    {
        return $this->values[self::key($organizationId, $feature)] ?? null;
    }

    public function put(int $organizationId, Feature $feature, FeatureValue $value): FeatureValue
    {
        return $this->values[self::key($organizationId, $feature)] = $value;
    }

    public function forget(): void
    {
        $this->values = [];
    }

    private static function key(int $organizationId, Feature $feature): string
    {
        return $organizationId.':'.$feature->value.':'.CarbonImmutable::now('Africa/Lagos')->toDateString();
    }
}
