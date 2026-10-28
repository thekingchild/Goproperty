<?php

declare(strict_types=1);

namespace Modules\Billing\Bills;

use App\Documents\BrandingSnapshot;
use App\Documents\BrandingSnapshots;
use App\Support\Money;
use App\Tenancy\OrganizationContext;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use LogicException;
use Modules\Billing\Enums\BillableType;
use Modules\Billing\Enums\BillType;
use Modules\Billing\Enums\MeterUnit;
use Modules\Billing\Enums\UtilityBillingHold;
use Modules\Billing\Models\Bill;
use Modules\Billing\Models\UnitUtilitySetting;
use Modules\Billing\Models\UtilityReading;
use Modules\Billing\Utilities\MeteredCharge;
use Modules\Leasing\Contracts\BillingPeriod;
use Modules\Leasing\Contracts\CycleCalculator;
use Modules\Leasing\Contracts\LeaseSummary;
use Modules\Leasing\Contracts\TenantLeases;
use Modules\Properties\Contracts\UnitDirectory;
use Throwable;

/**
 * Utility bills from units' utility settings (P03-06b; ARCHITECTURE.md
 * § 6.4 "How utility bills are built"), for the current organization, run
 * by Jobs\GenerateBills after the rent bills.
 *
 * Each switched-on setting of a utility that is not archived bills on its
 * own cycle (the setting's, else the type's), with periods counted from
 * its `effective_from` by the calculator (AGENT.md § 3 rule 3), from
 * `next_billing_date` on (null: from `effective_from`):
 *
 * - **Flat:** the setting's amount (else the type's) for each period, made
 *   on the period's bill date (CycleCalculator::billingDate(), the
 *   organization's `billing.lead_days` before the period starts, as rent),
 *   due on the period's first day.
 * - **Metered:** after the period has ended, the meter readings not yet
 *   billed and dated on or before its last day — one bill line each, its
 *   consumption (current − previous, already worked out per reading, so a
 *   replaced meter counts from the new meter's first value) times the rate
 *   kept on the reading, in kobo (Utilities\MeteredCharge). So a period's
 *   consumption is from the reading billed before to the latest reading in
 *   the period; a reading taken late (dated after the period) is billed
 *   with the next period. Due `billing.lead_days` after the day it is made.
 *   **Nothing is ever estimated:** a period without a reading waits (the
 *   setting shows "Waiting for a meter reading") until the next period has
 *   ended too, then closes without a bill; a later reading is billed with a
 *   later period. A period whose readings come to nothing (no consumption)
 *   closes without a bill; its readings are billed with the next bill.
 * - **Who pays:** the tenant of the unit's lease covering the period's
 *   first day (flat) or last day (metered) — Bills\UnitTenancies, through
 *   the Leasing contract. A metered bill takes only readings dated on or
 *   after that lease's start, so a tenant never pays for a meter read
 *   before they came. No such lease: the period waits while it can still
 *   be billed (flat: until it ends; metered: until the next one ends), then
 *   closes without a bill. Either way the setting is flagged
 *   (`billing_hold`, `billing_hold_since`) until a bill clears it.
 * - **A changed cycle or `effective_from`** takes effect from the next
 *   whole period of the new schedule (days already billed are never
 *   billed again).
 *
 * Each period is one transaction (DB::transaction($callback, 3)) under the
 * setting's row lock (the meter's lock, Utilities\MeterReadings uses it
 * too): read the setting as it is now, issue the bill (Bills\BillIssuer),
 * link the readings it bills (`utility_readings.bill_id`, once), move the
 * setting on. The natural key `(unit_utility_setting_id, period_start)` is
 * unique in the database too: a re-run, or two runs at once, never bill a
 * period twice (AGENT.md § 3 rule 6). Utility bills leave `lease_id` empty
 * (rent's key is per lease); the lease's number is kept with the parties.
 */
final readonly class UtilityBillGenerator
{
    /** Settings read at a time. */
    public const int PAGE = 100;

    private const string NATURAL_KEY = 'bills_utility_setting_period_unique';

    public function __construct(
        private OrganizationContext $context,
        private CycleCalculator $calculator,
        private BillIssuer $issuer,
        private BillService $bills,
        private BrandingSnapshots $snapshots,
        private TenantLeases $leases,
        private UnitDirectory $units,
    ) {}

    /**
     * Bills every utility period due in the current organization today
     * (Lagos). Stops early, returning false, when $shouldStop says so; a
     * later run carries on where it stopped. A setting that fails is
     * reported and left for the next run; the others go on.
     *
     * @param  (Closure(): bool)|null  $shouldStop
     */
    public function generate(?Closure $shouldStop = null): bool
    {
        $today = BillDates::today();
        $lead = $this->bills->leadDays();
        $cutoff = $this->calculator->daysAfter($today, $lead)->toDateString();
        $tenancies = new UnitTenancies($this->leases);
        $snapshot = null;
        // Taken once per run, and only when there is something to bill.
        $branding = function () use (&$snapshot): BrandingSnapshot {
            return $snapshot ??= $this->snapshots->take($this->context->get());
        };
        $afterId = 0;

        do {
            $page = UnitUtilitySetting::query()
                ->where('active', true)
                ->whereHas('utilityType', fn (Builder $query) => $query->where('active', true))
                ->where(fn (Builder $query) => $query
                    ->where('next_billing_date', '<=', $cutoff)
                    ->orWhere(fn (Builder $query) => $query->whereNull('next_billing_date')->where('effective_from', '<=', $cutoff)))
                ->where('id', '>', $afterId)
                ->orderBy('id')
                ->limit(self::PAGE)
                ->get(['id', 'unit_id']);
            // Archived units' utilities bill nothing.
            $units = $this->units->findMany(array_values(array_unique($page->pluck('unit_id')->map(fn (mixed $id): int => (int) $id)->all())));

            foreach ($page as $setting) {
                $afterId = $setting->id;

                if (! array_key_exists($setting->unit_id, $units)) {
                    continue;
                }

                try {
                    if (! $this->billSetting($setting->id, $today, $lead, $tenancies, $branding, $shouldStop)) {
                        return false;
                    }
                } catch (Throwable $failure) {
                    report($failure);
                }
            }
        } while ($page->count() === self::PAGE);

        return true;
    }

    /**
     * Bills the setting's periods due by today, one transaction each; false
     * when stopped before the setting was done.
     *
     * @param  Closure(): BrandingSnapshot  $branding
     * @param  (Closure(): bool)|null  $shouldStop
     */
    public function billSetting(int $settingId, CarbonImmutable $today, int $lead, UnitTenancies $tenancies, Closure $branding, ?Closure $shouldStop = null): bool
    {
        $duplicates = 0;

        while (true) {
            if ($shouldStop !== null && $shouldStop()) {
                return false;
            }

            try {
                $outcome = DB::transaction(fn (): string => $this->billNextPeriod($settingId, $today, $lead, $tenancies, $branding), 3);
            } catch (UniqueConstraintViolationException $duplicate) {
                if (! str_contains($duplicate->getMessage(), self::NATURAL_KEY) || ++$duplicates > 3) {
                    throw $duplicate;
                }

                // Another run billed this period between our check and our
                // insert (the setting's lock makes this all but impossible):
                // nothing was written; look again.
                continue;
            }

            if ($outcome === 'done') {
                return true;
            }
        }
    }

    /**
     * Inside the transaction: 'billed', 'closed' (the period ended without a
     * bill, the setting moved on), 'moved' (realigned to its schedule) or
     * 'done' (nothing more to bill today).
     *
     * @param  Closure(): BrandingSnapshot  $branding
     */
    private function billNextPeriod(int $settingId, CarbonImmutable $today, int $lead, UnitTenancies $tenancies, Closure $branding): string
    {
        $setting = UnitUtilitySetting::query()->with('utilityType')->whereKey($settingId)->lockForUpdate()->first();

        if (! $setting instanceof UnitUtilitySetting || ! $setting->active || ! $setting->utilityType->active) {
            return 'done';
        }

        $anchor = $setting->effective_from;
        $cycle = $setting->billingCycle();
        $next = $setting->next_billing_date;
        $cursor = $next === null || $next->lessThan($anchor) ? $anchor : $next;
        $period = $this->calculator->periodContaining($anchor, $cycle, $cursor);

        if (! $period->start->isSameDay($cursor)) {
            // The cycle or the date it applies from changed since the last
            // bill: carry on from the next whole period of the new schedule.
            $this->moveOn($setting, $this->calculator->nextPeriodStart($anchor, $cycle, $cursor));
            $setting->save();

            return 'moved';
        }

        return $setting->isMetered()
            ? $this->metered($setting, $period, $today, $lead, $tenancies, $branding)
            : $this->flat($setting, $period, $today, $lead, $tenancies, $branding);
    }

    /** @param  Closure(): BrandingSnapshot  $branding */
    private function flat(UnitUtilitySetting $setting, BillingPeriod $period, CarbonImmutable $today, int $lead, UnitTenancies $tenancies, Closure $branding): string
    {
        if ($this->calculator->billingDate($period->start, $lead)->greaterThan($today)) {
            return 'done';
        }

        $lease = $tenancies->on($setting->property_id, $setting->unit_id, $period->start);

        if ($lease === null) {
            return $this->hold($setting, $period, UtilityBillingHold::NoTenant, closable: $today->greaterThan($period->end));
        }

        if (! $this->billed($setting, $period)) {
            $type = $setting->utilityType;
            $cycle = $setting->billingCycle();

            $this->issuer->issue($this->draft($setting, $period, $lease, $this->calculator->dueDate($period->start), [new BillLine(
                label: $type->name,
                amount: $setting->amount(),
                note: __('Flat charge, :cycle', ['cycle' => mb_strtolower(__($cycle->label()))]),
                periodStart: $period->start,
                periodEnd: $period->end,
                meta: ['utility_setting_id' => $setting->id, 'utility' => $type->code, 'charging' => 'flat', 'cycle' => $cycle->value],
            )]), $branding());
        }

        return $this->billedAndMovedOn($setting, $period);
    }

    /** @param  Closure(): BrandingSnapshot  $branding */
    private function metered(UnitUtilitySetting $setting, BillingPeriod $period, CarbonImmutable $today, int $lead, UnitTenancies $tenancies, Closure $branding): string
    {
        if (! $today->greaterThan($period->end)) {
            return 'done';
        }

        // A period waits for its reading (or its tenant) until the next one has ended too.
        $following = $this->calculator->period($setting->effective_from, $setting->billingCycle(), $period->index + 1);
        $closable = $today->greaterThan($following->end);
        $lease = $tenancies->on($setting->property_id, $setting->unit_id, $period->end);

        if ($lease === null) {
            return $this->hold($setting, $period, UtilityBillingHold::NoTenant, $closable);
        }

        $readings = UtilityReading::query()
            ->where('unit_utility_setting_id', $setting->id)
            ->whereNull('bill_id')
            ->where('reading_date', '>=', $lease->startDate->toDateString())
            ->where('reading_date', '<=', $period->end->toDateString())
            ->orderBy('reading_date')
            ->orderBy('id')
            ->get();

        if ($readings->isEmpty()) {
            return $this->hold($setting, $period, UtilityBillingHold::NoReading, $closable);
        }

        $type = $setting->utilityType;
        $unit = __(($type->unit_of_measure ?? MeterUnit::Unit)->label());
        $lines = $readings->map(fn (UtilityReading $reading): BillLine => new BillLine(
            label: $type->name,
            amount: MeteredCharge::of($reading->consumption, $reading->rate_kobo),
            note: __('Read :date: :previous to :current, :consumption :unit at :rate', [
                'date' => $reading->reading_date->format('d/m/Y'),
                'previous' => $reading->previous_value->format(),
                'current' => $reading->current_value->format(),
                'consumption' => $reading->consumption->format(),
                'unit' => $unit,
                'rate' => $reading->rate_kobo->format(),
            ]).($reading->meter_replaced ? ' '.__('(new meter)') : ''),
            periodStart: $period->start,
            periodEnd: $period->end,
            meta: [
                'utility_setting_id' => $setting->id,
                'utility' => $type->code,
                'charging' => 'metered',
                'reading_id' => $reading->id,
                'reading_date' => $reading->reading_date->toDateString(),
                'previous' => $reading->previous_value->toDecimalString(),
                'current' => $reading->current_value->toDecimalString(),
                'consumption' => $reading->consumption->toDecimalString(),
                'unit' => ($type->unit_of_measure ?? MeterUnit::Unit)->value,
                'rate_kobo' => $reading->rate_kobo->kobo,
                'meter_replaced' => $reading->meter_replaced,
            ],
        ))->all();
        $lines = array_values($lines);

        if (! Money::sum(array_map(static fn (BillLine $line): Money => $line->amount, $lines))->isPositive()) {
            // Nothing used: no bill; these readings go on the next one.
            $setting->billing_hold = null;
            $setting->billing_hold_since = null;
            $this->moveOn($setting, $this->calculator->nextPeriodStart($setting->effective_from, $setting->billingCycle(), $period->start));
            $setting->save();

            return 'closed';
        }

        if (! $this->billed($setting, $period)) {
            $bill = $this->issuer->issue($this->draft($setting, $period, $lease, $this->calculator->dueDate($today, $lead), $lines), $branding());
            $linked = UtilityReading::query()->whereKey($readings->modelKeys())->whereNull('bill_id')->update(['bill_id' => $bill->id]);

            if ($linked !== $readings->count()) {
                throw new LogicException("Meter readings of utility setting {$setting->id} were billed by another bill meanwhile.");
            }
        }

        return $this->billedAndMovedOn($setting, $period);
    }

    /**
     * Flags the setting with why its period is not billed; when the period
     * can no longer be billed ($closable) it is closed without a bill and
     * the setting moves on, else it waits ('done').
     */
    private function hold(UnitUtilitySetting $setting, BillingPeriod $period, UtilityBillingHold $reason, bool $closable): string
    {
        if ($setting->billing_hold !== $reason) {
            $setting->billing_hold = $reason;
            $setting->billing_hold_since = $period->start;
        }

        if ($closable) {
            $this->moveOn($setting, $this->calculator->nextPeriodStart($setting->effective_from, $setting->billingCycle(), $period->start));
        }

        if ($setting->isDirty()) {
            $setting->save();
        }

        return $closable ? 'closed' : 'done';
    }

    private function billedAndMovedOn(UnitUtilitySetting $setting, BillingPeriod $period): string
    {
        $setting->billing_hold = null;
        $setting->billing_hold_since = null;
        $this->moveOn($setting, $this->calculator->nextPeriodStart($setting->effective_from, $setting->billingCycle(), $period->start));
        $setting->save();

        return 'billed';
    }

    private function moveOn(UnitUtilitySetting $setting, CarbonImmutable $nextPeriodStart): void
    {
        $setting->next_billing_date = $nextPeriodStart;
    }

    /**
     * Whether the period has its bill already. The setting's row lock is
     * held: this sees every bill another run committed for the setting
     * before it let go of the lock.
     */
    private function billed(UnitUtilitySetting $setting, BillingPeriod $period): bool
    {
        return Bill::query()
            ->where('unit_utility_setting_id', $setting->id)
            ->where('period_start', $period->start->toDateString())
            ->exists();
    }

    /** @param  list<BillLine>  $lines */
    private function draft(UnitUtilitySetting $setting, BillingPeriod $period, LeaseSummary $lease, CarbonImmutable $dueDate, array $lines): BillDraft
    {
        $dates = __(':start to :end', ['start' => $period->start->format('d/m/Y'), 'end' => $period->end->format('d/m/Y')]);

        return new BillDraft(
            type: BillType::Utility,
            billableType: BillableType::Unit,
            billableId: $setting->unit_id,
            tenantProfileId: $lease->tenantProfileId,
            description: __(':utility, :dates', ['utility' => $setting->utilityType->name, 'dates' => $dates]),
            dueDate: $dueDate,
            lines: $lines,
            propertyId: $setting->property_id,
            unitId: $setting->unit_id,
            periodStart: $period->start,
            periodEnd: $period->end,
            leaseNumber: $lease->contractNumber,
            cycle: $lease->cycle,
            unitUtilitySettingId: $setting->id,
        );
    }
}
