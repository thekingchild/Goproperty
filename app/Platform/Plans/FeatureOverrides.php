<?php

declare(strict_types=1);

namespace App\Platform\Plans;

use App\Audit\AuditContext;
use App\Models\Organization;
use App\Models\OrganizationFeatureOverride;
use App\Models\PlatformStaff;
use App\Plans\Feature;
use App\Plans\Features;
use App\Plans\FeatureValue;
use App\Platform\Customers\CustomerChangeRefused;
use App\Platform\Staff\StaffPermission;
use App\Tenancy\OrganizationContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Features granted or withheld for one organization outside its plan
 * (P06-07a; ARCHITECTURE.md § 6.10, PLATFORM_CONSOLE.md § 1B,
 * docs/plans/P06-subtasks.md default 53). The only writer of
 * `organization_feature_overrides`; App\Plans\Features reads them (an
 * override in force wins over the plan; one past its end stops applying by
 * itself).
 *
 * - set(): replaces the feature's override (one per organization and
 *   feature): a switch on or off, or a limit (null = unlimited), with a
 *   reason (3–500 characters) and an optional end in the future.
 * - end(): ends the override in force now (`expires_at`); the row is kept.
 *
 * Both need `customers.override_features` (re-checked here), run inside the
 * organization's context under its lock, and write one audit entry (log
 * `customers`, `feature_override.set` / `feature_override.ended`, the
 * organization as subject so it shows on its History, the staff member as
 * causer, before and after). `sms_credits` is not overridden here: the SMS
 * allowance on the same page sets it. The owners are not told.
 */
final readonly class FeatureOverrides
{
    public const string LOG = 'customers';

    public const int REASON_MIN = 3;

    public const int REASON_MAX = 500;

    /** The largest limit staff may set (the column is unsigned int). */
    public const int LIMIT_MAX = 100_000;

    public function __construct(
        private OrganizationContext $context,
        private Features $features,
        private AuditContext $audit,
    ) {}

    /**
     * The features staff may override, in the registry's order.
     *
     * @return list<Feature>
     */
    public static function overridable(): array
    {
        $features = [];

        foreach (Feature::cases() as $feature) {
            if ($feature !== Feature::SmsCredits) {
                $features[] = $feature;
            }
        }

        return $features;
    }

    /** @return list<FeatureStanding> */
    public function standings(Organization $organization): array
    {
        $plan = $this->features->planFor($organization);
        $overrides = $this->context->run($organization, static fn () => OrganizationFeatureOverride::query()
            ->where(static fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', CarbonImmutable::now()))
            ->get()
            ->keyBy('feature_key'));
        $staff = PlatformStaff::query()->whereIn('id', $overrides->pluck('granted_by_staff_id')->filter()->all())->pluck('name', 'id');

        return array_map(function (Feature $feature) use ($organization, $plan, $overrides, $staff): FeatureStanding {
            $override = $overrides->get($feature->value);
            $planValue = $plan?->feature($feature);

            if (! $override instanceof OrganizationFeatureOverride) {
                return new FeatureStanding($feature, $planValue, $this->features->value($feature, $organization));
            }

            $granter = $override->granted_by_staff_id !== null ? $staff->get($override->granted_by_staff_id) : null;

            return new FeatureStanding(
                $feature,
                $planValue,
                $this->features->value($feature, $organization),
                $override->value(),
                $override->reason,
                is_string($granter) ? $granter : null,
                $override->updated_at,
                $override->expires_at,
            );
        }, self::overridable());
    }

    /**
     * @param  bool|null  $enabled  a switch: on or off (ignored for a limit)
     * @param  int|null  $limit  a limit: null = unlimited (ignored for a switch)
     *
     * @throws CustomerChangeRefused
     */
    public function set(PlatformStaff $staff, Organization $organization, Feature $feature, ?bool $enabled, ?int $limit, string $reason, ?CarbonImmutable $until = null): OrganizationFeatureOverride
    {
        $this->authorize($staff);
        $this->overridableOrRefuse($feature);
        $reason = $this->reason($reason);

        if ($feature->isLimit() && $limit !== null && ($limit < 0 || $limit > self::LIMIT_MAX)) {
            throw new CustomerChangeRefused('A limit is between 0 and '.number_format(self::LIMIT_MAX).', or empty for unlimited.');
        }

        if (! $feature->isLimit() && $enabled === null) {
            throw new CustomerChangeRefused('Choose whether the feature is switched on or off.');
        }

        if ($until !== null && ! $until->isFuture()) {
            throw new CustomerChangeRefused('The end of an override must be in the future.');
        }

        $value = FeatureValue::of($feature, $enabled ?? true, $limit);
        // Stored in the application's time zone, as every other moment (a Lagos midnight stays that moment).
        $until = $until?->setTimezone(date_default_timezone_get());

        return $this->inOrganization($organization, function () use ($staff, $organization, $feature, $value, $reason, $until): OrganizationFeatureOverride {
            $override = OrganizationFeatureOverride::query()->where('feature_key', $feature->value)->lockForUpdate()->first();
            $before = $override instanceof OrganizationFeatureOverride && $override->inForce() ? $this->describe($feature, $override) : null;

            $override ??= new OrganizationFeatureOverride;
            $override->forceFill([
                'organization_id' => $organization->id,
                'feature_key' => $feature->value,
                'enabled' => $value->enabled,
                'limit_value' => $feature->isLimit() ? $value->limit : null,
                'reason' => $reason,
                'granted_by_staff_id' => $staff->id,
                'expires_at' => $until,
            ])->save();

            $this->record('feature_override.set', $organization, $staff, $reason, $feature, $before, $this->describe($feature, $override),
                "{$feature->label()}: {$value->describe($feature)} for {$organization->name}".($until !== null ? ' until '.$until->setTimezone('Africa/Lagos')->format('j M Y') : ''));

            return $override;
        });
    }

    /** @throws CustomerChangeRefused */
    public function end(PlatformStaff $staff, Organization $organization, Feature $feature, ?string $reason = null): OrganizationFeatureOverride
    {
        $this->authorize($staff);
        $this->overridableOrRefuse($feature);
        $reason = $reason !== null && trim($reason) !== '' ? $this->reason($reason) : null;

        return $this->inOrganization($organization, function () use ($staff, $organization, $feature, $reason): OrganizationFeatureOverride {
            $override = OrganizationFeatureOverride::query()->where('feature_key', $feature->value)->lockForUpdate()->first();

            if (! $override instanceof OrganizationFeatureOverride || ! $override->inForce()) {
                throw new CustomerChangeRefused("{$organization->name} has no override of {$feature->label()} in force.");
            }

            $before = $this->describe($feature, $override);
            $override->forceFill(['expires_at' => CarbonImmutable::now()])->save();

            $this->record('feature_override.ended', $organization, $staff, $reason, $feature, $before, null,
                "{$feature->label()}: override ended for {$organization->name}, the plan applies again");

            return $override;
        });
    }

    /**
     * @template T
     *
     * @param  callable(): T  $change
     * @return T
     */
    private function inOrganization(Organization $organization, callable $change): mixed
    {
        return $this->context->run($organization, static fn (): mixed => DB::transaction(static function () use ($organization, $change): mixed {
            // One writer at a time per organization (the row is unique per feature).
            Organization::query()->whereKey($organization->getKey())->lockForUpdate()->firstOrFail();

            return $change();
        }, 3));
    }

    /** @return array{value: string, enabled: bool, limit: int|null, until: string|null} */
    private function describe(Feature $feature, OrganizationFeatureOverride $override): array
    {
        $value = FeatureValue::of($feature, $override->enabled, $override->limit_value);

        return [
            'value' => $value->describe($feature),
            'enabled' => $value->enabled,
            'limit' => $value->limit,
            'until' => $override->expires_at?->toIso8601String(),
        ];
    }

    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    private function record(string $event, Organization $organization, PlatformStaff $staff, ?string $reason, Feature $feature, ?array $before, ?array $after, string $description): void
    {
        $write = static function () use ($event, $organization, $staff, $feature, $before, $after, $description): void {
            activity(self::LOG)
                ->event($event)
                ->performedOn($organization)
                ->causedBy($staff)
                ->withProperties([
                    'organization_id' => $organization->id,
                    'feature' => $feature->value,
                    'old' => $before,
                    'attributes' => $after,
                ])
                ->log($description);
        };

        $reason !== null ? $this->audit->because($reason, $write) : $write();
    }

    /** @throws CustomerChangeRefused */
    private function authorize(PlatformStaff $staff): void
    {
        if (! $staff->holdsStaffPermission(StaffPermission::CustomersView) || ! $staff->holdsStaffPermission(StaffPermission::CustomersOverrideFeatures)) {
            throw new CustomerChangeRefused('You do not have permission to override plan features.');
        }
    }

    /** @throws CustomerChangeRefused */
    private function overridableOrRefuse(Feature $feature): void
    {
        if ($feature === Feature::SmsCredits) {
            throw new CustomerChangeRefused('SMS credits are set with SMS allowance on the organization\'s page.');
        }
    }

    /** @throws CustomerChangeRefused */
    private function reason(string $reason): string
    {
        $reason = trim($reason);

        if (mb_strlen($reason) < self::REASON_MIN || mb_strlen($reason) > self::REASON_MAX) {
            throw new CustomerChangeRefused('Give a reason of '.self::REASON_MIN.' to '.self::REASON_MAX.' characters: it is kept with the override and in the audit log.');
        }

        return $reason;
    }
}
