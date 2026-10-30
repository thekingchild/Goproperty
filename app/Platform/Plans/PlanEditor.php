<?php

declare(strict_types=1);

namespace App\Platform\Plans;

use App\Enums\SubscriptionStatus;
use App\Marketing\PlanCatalogue;
use App\Models\PlanFeature;
use App\Models\PlanVersion;
use App\Models\PlatformStaff;
use App\Models\SubscriptionPlan;
use App\Plans\Feature;
use App\Platform\Staff\StaffPermission;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * finance-admin's plan editor (P06-01a; BILLING_PAYMENTS.md § 1.3,
 * PLATFORM_CONSOLE.md § 1A, `plans.manage`; docs/plans/P06-subtasks.md
 * defaults 2–5). The only writer of `plans`, `plan_versions` and
 * `plan_feature`.
 *
 * - updateDetails(): name, audience, landing lines, featured, order,
 *   offered to new signups, trial days: at once (they change no bill).
 * - schedule(): new priced terms and features as the plan's next version.
 *   **A plan that customers are on (a subscription not cancelled) changes
 *   no sooner than 30 days after this save** (NOTICE_DAYS, Lagos dates):
 *   saving is the notice, emailed and shown in the app by
 *   SendPlanChangeNotices. A plan nobody is on may change from today. One
 *   scheduled version at a time: cancel it to schedule another.
 * - cancel(): a scheduled version, before its date.
 * - create(): a new plan, its version 1 in effect at once.
 *
 * Every change is one audit entry (log `plans`) with the staff member.
 */
final readonly class PlanEditor
{
    public const string LOG = 'plans';

    public const int NOTICE_DAYS = 30;

    public function __construct(private PlanCatalogue $catalogue) {}

    /**
     * @param  array{name?: string, audience?: string, highlights?: list<string>, featured?: bool, trial_days?: int, is_active?: bool, sort_order?: int}  $details
     *
     * @throws PlanChangeRefused
     */
    public function updateDetails(PlatformStaff $actor, SubscriptionPlan $plan, array $details): SubscriptionPlan
    {
        $this->authorize($actor);
        $details = $this->checkedDetails($details);

        return DB::transaction(function () use ($actor, $plan, $details): SubscriptionPlan {
            $locked = SubscriptionPlan::query()->whereKey($plan->id)->lockForUpdate()->firstOrFail();
            $old = array_intersect_key($locked->only(array_keys($details)), $details);
            $locked->forceFill($details);

            if (! $locked->isDirty()) {
                return $locked;
            }

            $changed = array_keys($locked->getDirty());
            $locked->save();
            $this->catalogue->flush();

            $this->audit('plan.updated', $locked, $actor, "Edited the {$locked->name} plan", [
                'old' => array_intersect_key($old, array_flip($changed)),
                'attributes' => $locked->only($changed),
            ]);

            return $locked;
        });
    }

    /**
     * The next version of a plan's terms, from $effectiveFrom (a Lagos date).
     *
     * @throws PlanChangeRefused
     */
    public function schedule(PlatformStaff $actor, SubscriptionPlan $plan, PlanTerms $terms, CarbonImmutable $effectiveFrom, string $reason): PlanVersion
    {
        $this->authorize($actor);
        $reason = $this->checkedReason($reason);
        $this->checkTerms($terms, $plan->custom);
        $day = CarbonImmutable::parse($effectiveFrom->format('Y-m-d'), 'Africa/Lagos')->startOfDay();

        $version = DB::transaction(function () use ($actor, $plan, $terms, $day, $reason): PlanVersion {
            $locked = SubscriptionPlan::query()->whereKey($plan->id)->lockForUpdate()->firstOrFail();
            $customers = $this->customers($locked->id);
            $earliest = $this->earliestDate($locked, $customers);

            if ($day->lt($earliest)) {
                throw new PlanChangeRefused($customers > 0
                    ? "{$locked->name} has {$customers} customer".($customers === 1 ? '' : 's').' who must be told '.self::NOTICE_DAYS.' days ahead: the earliest date is '.$earliest->format('j F Y').'.'
                    : 'The date cannot be in the past.');
            }

            $pending = $this->catalogue->scheduled($locked->id);

            if ($pending instanceof PlanVersion) {
                throw new PlanChangeRefused("Version {$pending->version} of {$locked->name} is already scheduled for ".$pending->effective_from->format('j F Y').'. Cancel it first.');
            }

            $version = new PlanVersion;
            $version->forceFill([
                'plan_id' => $locked->id,
                'version' => ((int) PlanVersion::query()->where('plan_id', $locked->id)->max('version')) + 1,
                'base_price_kobo' => $terms->basePriceKobo,
                'included_units' => $terms->includedUnits,
                'unit_price_kobo' => $terms->unitPriceKobo,
                'effective_from' => $day->toDateString(),
                'notice_given_at' => $customers > 0 ? now() : null,
                'reason' => $reason,
                'created_by_staff_id' => $actor->id,
            ])->save();

            $this->writeFeatures($version, $terms);
            $this->catalogue->flush();

            $this->audit('plan.version_scheduled', $locked, $actor, "Scheduled version {$version->version} of {$locked->name} from ".$day->format('j F Y'), [
                'version' => $version->version,
                'effective_from' => $day->toDateString(),
                'customers_told' => $customers,
                'attributes' => $this->termsForAudit($terms),
                'reason' => $reason,
            ]);

            return $version;
        });

        if ($version->notice_given_at !== null) {
            SendPlanChangeNotices::dispatch($version->id)->afterCommit();
        }

        return $version;
    }

    /** @throws PlanChangeRefused */
    public function cancel(PlatformStaff $actor, PlanVersion $version, string $reason): PlanVersion
    {
        $this->authorize($actor);
        $reason = $this->checkedReason($reason);

        return DB::transaction(function () use ($actor, $version, $reason): PlanVersion {
            $locked = PlanVersion::query()->whereKey($version->id)->lockForUpdate()->firstOrFail();

            if ($locked->cancelled_at !== null) {
                throw new PlanChangeRefused('That version is already cancelled.');
            }

            if ($locked->effective_from->toDateString() <= PlanCatalogue::day()) {
                throw new PlanChangeRefused('That version is already in effect: schedule a new one instead.');
            }

            $locked->forceFill(['cancelled_at' => now(), 'cancelled_by_staff_id' => $actor->id])->save();
            $this->catalogue->flush();

            $this->audit('plan.version_cancelled', $locked->plan, $actor, "Cancelled version {$locked->version} of {$locked->plan->name}", [
                'version' => $locked->version,
                'effective_from' => $locked->effective_from->toDateString(),
                'reason' => $reason,
            ]);

            return $locked;
        });
    }

    /**
     * @param  array{key: string, name: string, audience?: string, highlights?: list<string>, featured?: bool, trial_days?: int, is_active?: bool, sort_order?: int, custom?: bool}  $details
     *
     * @throws PlanChangeRefused
     */
    public function create(PlatformStaff $actor, array $details, PlanTerms $terms, string $reason): SubscriptionPlan
    {
        $this->authorize($actor);
        $reason = $this->checkedReason($reason);
        $key = strtolower(trim($details['key']));

        if (preg_match('/^[a-z][a-z0-9_]{1,31}$/', $key) !== 1) {
            throw new PlanChangeRefused('The key is 2 to 32 lower-case letters, digits or underscores, starting with a letter.');
        }

        $custom = (bool) ($details['custom'] ?? false);
        $this->checkTerms($terms, $custom);
        $checked = $this->checkedDetails(array_diff_key($details, ['key' => true, 'custom' => true]));

        return DB::transaction(function () use ($actor, $key, $custom, $checked, $terms, $reason): SubscriptionPlan {
            if (SubscriptionPlan::query()->where('key', $key)->lockForUpdate()->exists()) {
                throw new PlanChangeRefused("A plan with the key {$key} exists.");
            }

            $plan = new SubscriptionPlan;
            $plan->forceFill($checked + [
                'key' => $key,
                'custom' => $custom,
                'audience' => '',
                'trial_days' => 14,
                'is_active' => true,
                'sort_order' => ((int) SubscriptionPlan::query()->max('sort_order')) + 10,
            ])->save();

            $version = new PlanVersion;
            $version->forceFill([
                'plan_id' => $plan->id,
                'version' => 1,
                'base_price_kobo' => $terms->basePriceKobo,
                'included_units' => $terms->includedUnits,
                'unit_price_kobo' => $terms->unitPriceKobo,
                'effective_from' => PlanCatalogue::day(),
                'reason' => $reason,
                'created_by_staff_id' => $actor->id,
            ])->save();

            $this->writeFeatures($version, $terms);
            $this->catalogue->flush();

            $this->audit('plan.created', $plan, $actor, "Created the {$plan->name} plan", [
                'attributes' => $plan->only(['key', 'name', 'custom', 'is_active']) + $this->termsForAudit($terms),
                'reason' => $reason,
            ]);

            return $plan;
        });
    }

    /** Customers on the plan: subscriptions that are not cancelled, across organizations. */
    public function customers(int $planId): int
    {
        return DB::table('subscriptions')->where('plan_id', $planId)->where('status', '!=', SubscriptionStatus::Cancelled->value)->count();
    }

    /** The first Lagos date a new version may start: today, or 30 days on when customers are on the plan. */
    public function earliestDate(SubscriptionPlan $plan, ?int $customers = null): CarbonImmutable
    {
        $today = CarbonImmutable::now('Africa/Lagos')->startOfDay();

        return ($customers ?? $this->customers($plan->id)) > 0 ? $today->addDays(self::NOTICE_DAYS) : $today;
    }

    /** What the editor proposes: the first billing day (the 1st) on or after the earliest date. */
    public function suggestedDate(SubscriptionPlan $plan): CarbonImmutable
    {
        $earliest = $this->earliestDate($plan);

        return $earliest->day === 1 ? $earliest : $earliest->startOfMonth()->addMonthNoOverflow();
    }

    private function writeFeatures(PlanVersion $version, PlanTerms $terms): void
    {
        $now = now();

        PlanFeature::query()->insert(array_map(static function (Feature $feature) use ($version, $terms, $now): array {
            $value = $terms->feature($feature);

            return [
                'plan_version_id' => $version->id,
                'feature_key' => $feature->value,
                'enabled' => $value->enabled,
                'limit_value' => $feature->isLimit() ? $value->limit : null,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }, Feature::cases()));
    }

    /** @throws PlanChangeRefused */
    private function checkTerms(PlanTerms $terms, bool $custom): void
    {
        $max = 100_000_000_00; // ₦100m a month

        if ($terms->basePriceKobo < 0 || $terms->basePriceKobo > $max || ($terms->unitPriceKobo !== null && ($terms->unitPriceKobo < 0 || $terms->unitPriceKobo > $max))) {
            throw new PlanChangeRefused('Prices are between ₦0 and ₦100,000,000 a month.');
        }

        if ($terms->includedUnits < 0 || $terms->includedUnits > 1_000_000) {
            throw new PlanChangeRefused('Units included are between 0 and 1,000,000.');
        }

        if ($terms->unitPriceKobo === null && ! $custom) {
            throw new PlanChangeRefused('Give the price of each extra unit (only a custom plan has none).');
        }

        foreach (Feature::cases() as $feature) {
            $limit = $terms->feature($feature)->limit;

            if ($feature->isLimit() && $limit !== null && $limit > 1_000_000) {
                throw new PlanChangeRefused("{$feature->label()}: at most 1,000,000, or unlimited.");
            }
        }
    }

    /**
     * @param  array<string, mixed>  $details
     * @return array<string, mixed>
     *
     * @throws PlanChangeRefused
     */
    private function checkedDetails(array $details): array
    {
        $allowed = ['name', 'audience', 'highlights', 'featured', 'trial_days', 'is_active', 'sort_order'];
        $details = array_intersect_key($details, array_flip($allowed));

        if (array_key_exists('name', $details)) {
            $details['name'] = trim((string) $details['name']);

            if ($details['name'] === '' || mb_strlen($details['name']) > 60 || preg_match('/[<>]/', $details['name']) === 1) {
                throw new PlanChangeRefused('The name is 1 to 60 characters of plain text.');
            }
        }

        if (array_key_exists('audience', $details)) {
            $details['audience'] = trim((string) $details['audience']);

            if (mb_strlen($details['audience']) > 160 || preg_match('/[<>]/', $details['audience']) === 1) {
                throw new PlanChangeRefused('Who it is for is up to 160 characters of plain text.');
            }
        }

        if (array_key_exists('highlights', $details)) {
            $lines = array_values(array_filter(array_map(static fn (mixed $line): string => trim((string) $line), (array) $details['highlights']), static fn (string $line): bool => $line !== ''));

            if (count($lines) > 8 || array_filter($lines, static fn (string $line): bool => mb_strlen($line) > 80 || preg_match('/[<>]/', $line) === 1) !== []) {
                throw new PlanChangeRefused('Up to 8 lines for the pricing page, each up to 80 characters of plain text.');
            }

            $details['highlights'] = $lines;
        }

        if (array_key_exists('trial_days', $details)) {
            $details['trial_days'] = (int) $details['trial_days'];

            if ($details['trial_days'] < 0 || $details['trial_days'] > 90) {
                throw new PlanChangeRefused('Trial days are between 0 and 90.');
            }
        }

        foreach (['featured', 'is_active'] as $flag) {
            if (array_key_exists($flag, $details)) {
                $details[$flag] = (bool) $details[$flag];
            }
        }

        if (array_key_exists('sort_order', $details)) {
            $details['sort_order'] = max(0, min(65_000, (int) $details['sort_order']));
        }

        return $details;
    }

    /** @throws PlanChangeRefused */
    private function checkedReason(string $reason): string
    {
        $reason = trim($reason);

        if ($reason === '' || mb_strlen($reason) > 500) {
            throw new PlanChangeRefused('Give a reason (up to 500 characters): it is kept in the audit log.');
        }

        return $reason;
    }

    /** @throws PlanChangeRefused */
    private function authorize(PlatformStaff $actor): void
    {
        if (! $actor->holdsStaffPermission(StaffPermission::PlansManage)) {
            throw new PlanChangeRefused('You do not have permission to edit plans and prices.');
        }
    }

    /** @return array<string, mixed> */
    private function termsForAudit(PlanTerms $terms): array
    {
        $features = [];

        foreach (Feature::cases() as $feature) {
            $value = $terms->feature($feature);
            $features[$feature->value] = $feature->isLimit() ? $value->limit : $value->enabled;
        }

        return [
            'base_price_kobo' => $terms->basePriceKobo,
            'included_units' => $terms->includedUnits,
            'unit_price_kobo' => $terms->unitPriceKobo,
            'features' => $features,
        ];
    }

    /** @param  array<string, mixed>  $properties */
    private function audit(string $event, SubscriptionPlan $plan, PlatformStaff $actor, string $description, array $properties): void
    {
        activity(self::LOG)->event($event)->performedOn($plan)->causedBy($actor)->withProperties($properties)->log($description);
    }
}
