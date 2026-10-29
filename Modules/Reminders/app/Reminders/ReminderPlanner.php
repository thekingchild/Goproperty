<?php

declare(strict_types=1);

namespace Modules\Reminders\Reminders;

use App\Enums\OrganizationStatus;
use App\Tenancy\OrganizationContext;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;
use Modules\Billing\Contracts\RemindableBill;
use Modules\Billing\Contracts\RemindableBills;
use Modules\Leasing\Contracts\BillableLease;
use Modules\Leasing\Contracts\CycleCalculator;
use Modules\Leasing\Contracts\EndingLease;
use Modules\Leasing\Contracts\EndingLeases;
use Modules\Leasing\Contracts\LeaseBillingSchedule;
use Modules\Reminders\Enums\ReminderStatus;
use Modules\Reminders\Enums\ReminderType;
use Modules\Reminders\Models\Reminder;

/**
 * Works out the reminders whose day is today in the current organization
 * and writes them as `pending` rows (P05-01; ARCHITECTURE.md § 6.7;
 * docs/plans/P05-subtasks.md defaults 1–5, 9–11). The only writer of new
 * `reminders` rows.
 *
 * Exact days: a rung is planned only on its own day (no catch-up), counted
 * in Lagos calendar days through the cycle calculator (AGENT.md § 3 rule
 * 3). Today's rungs come from:
 *
 * - **bills** due on a rung's day (Billing's RemindableBills): rent bills
 *   on the rent ladder, utility bills on the utility ladder, and every
 *   bill still owing on the overdue ladder;
 * - **leases' next rent period before its bill exists** (Leasing's
 *   LeaseBillingSchedule): the early rent rungs (T−30, T−14 with the
 *   default 7-day lead); a rent rung goes once per lease period, from the
 *   lease or from its bill, whichever exists that day (one dedupe key);
 * - **active leases ending** on a rung's day (Leasing's EndingLeases),
 *   without a drafted renewal and once the lease has started: alerts to
 *   staff, not while the organization is suspended.
 *
 * A due rung is used only when it is at most half of the bill's period
 * (weekly: T−3 and closer; monthly: T−14 and closer); a bill whose period
 * is shorter than a week gets no reminders at all (default 2).
 *
 * Safe to run again (AGENT.md § 3 rule 6): each row's `dedupe_key` is
 * unique per organization; keys already planned are read first and a
 * concurrent run's row is caught by the unique index.
 */
final readonly class ReminderPlanner
{
    /** A bill whose period is shorter than this gets no reminders (daily rent). */
    public const int SHORTEST_PERIOD_DAYS = 7;

    private const int PAGE = 200;

    public function __construct(
        private OrganizationContext $context,
        private RemindableBills $bills,
        private LeaseBillingSchedule $schedule,
        private EndingLeases $ending,
        private CycleCalculator $calendar,
        private ReminderRecipients $recipients,
    ) {}

    /**
     * Plan today's reminders here. Returns false when $stop said to stop
     * before the end (the caller runs it again later; nothing is planned twice).
     *
     * @param  Closure(): bool  $stop
     */
    public function plan(CarbonImmutable $today, Closure $stop): bool
    {
        return $this->planBills($today, $stop)
            && $this->planLeasePeriods($today, $stop)
            && $this->planRenewals($today, $stop);
    }

    /** @param  Closure(): bool  $stop */
    private function planBills(CarbonImmutable $today, Closure $stop): bool
    {
        $dates = array_merge(
            $this->dates($today, ReminderType::RentDue),
            $this->dates($today, ReminderType::UtilityDue),
            $this->dates($today, ReminderType::BillOverdue),
        );
        $afterId = 0;

        do {
            if ($stop()) {
                return false;
            }

            $page = $this->bills->dueOn($dates, $afterId, self::PAGE);
            $rungs = [];

            foreach ($page as $bill) {
                $afterId = $bill->id;

                foreach ($this->billRungs($today, $bill) as $rung) {
                    $rungs[] = [$rung, $bill->tenantProfileId];
                }
            }

            $this->writeForTenants($today, $rungs);
        } while (count($page) === self::PAGE);

        return true;
    }

    /** @param  Closure(): bool  $stop */
    private function planLeasePeriods(CarbonImmutable $today, Closure $stop): bool
    {
        $rungs = ReminderType::RentDue->rungs();
        $afterId = 0;

        do {
            if ($stop()) {
                return false;
            }

            // Every billable lease whose next period starts within the
            // longest rung; the exact due date is checked below.
            $page = $this->schedule->due($today, max($rungs), $afterId, self::PAGE);
            $planned = [];

            foreach ($page as $lease) {
                $afterId = $lease->id;
                $rung = $this->leaseRung($today, $lease);

                if ($rung !== null) {
                    $planned[] = [$rung, $lease->tenantProfileId];
                }
            }

            $this->writeForTenants($today, $planned);
        } while (count($page) === self::PAGE);

        return true;
    }

    /** @param  Closure(): bool  $stop */
    private function planRenewals(CarbonImmutable $today, Closure $stop): bool
    {
        // Members of a suspended organization cannot renew anything (default 11).
        if ($this->context->get()->status === OrganizationStatus::Suspended) {
            return true;
        }

        $dates = $this->dates($today, ReminderType::LeaseRenewal);
        $afterId = 0;

        do {
            if ($stop()) {
                return false;
            }

            $page = $this->ending->endingOn($dates, $afterId, self::PAGE);

            foreach ($page as $ending) {
                $afterId = $ending->lease->id;
                $rung = $this->renewalRung($today, $ending);

                if ($rung !== null) {
                    $this->write($today, $rung, $this->recipients->staffFor($ending->lease->propertyId));
                }
            }
        } while (count($page) === self::PAGE);

        return true;
    }

    /** @return list<Rung> */
    private function billRungs(CarbonImmutable $today, RemindableBill $bill): array
    {
        if (! $bill->open || ! $this->periodTakes($bill->periodStart, $bill->periodEnd, 0)) {
            return [];
        }

        $due = match ($bill->type) {
            'rent' => ReminderType::RentDue,
            'utility' => ReminderType::UtilityDue,
            default => null,
        };
        $rungs = [];

        foreach (array_filter([$due, ReminderType::BillOverdue]) as $type) {
            $days = $this->rungDays($today, $type, $bill->dueDate);

            if ($days === null || ($type !== ReminderType::BillOverdue && ! $this->periodTakes($bill->periodStart, $bill->periodEnd, $days))) {
                continue;
            }

            $rentPeriod = $type === ReminderType::RentDue && $bill->leaseId !== null && $bill->periodStart !== null;

            $rungs[] = new Rung(
                type: $type,
                remindableType: Reminder::BILL,
                remindableId: $bill->id,
                subject: $rentPeriod ? self::leasePeriod((int) $bill->leaseId, $bill->periodStart) : 'bill:'.$bill->id,
                dueDate: $bill->dueDate,
                days: $days,
                leaseId: $bill->leaseId,
                periodStart: $bill->periodStart,
            );
        }

        return $rungs;
    }

    private function leaseRung(CarbonImmutable $today, BillableLease $lease): ?Rung
    {
        $periodStart = $lease->nextBillingDate;
        $due = $this->calendar->dueDate($periodStart, $lease->dueDayOffset);
        $days = $this->rungDays($today, ReminderType::RentDue, $due);

        if ($days === null) {
            return null;
        }

        $period = $this->calendar->periodContaining($lease->startDate, $lease->cycle, $periodStart);

        if (! $this->periodTakes($period->start, $period->end, $days)) {
            return null;
        }

        return new Rung(
            type: ReminderType::RentDue,
            remindableType: Reminder::LEASE,
            remindableId: $lease->id,
            subject: self::leasePeriod($lease->id, $periodStart),
            dueDate: $due,
            days: $days,
            leaseId: $lease->id,
            periodStart: $periodStart,
        );
    }

    private function renewalRung(CarbonImmutable $today, EndingLease $ending): ?Rung
    {
        // Drafted already, or the lease has not started yet (a short lease's early rungs).
        if ($ending->renewal !== null || $today->lessThan($ending->lease->startDate)) {
            return null;
        }

        $days = $this->rungDays($today, ReminderType::LeaseRenewal, $ending->lease->endDate);

        return $days === null ? null : new Rung(
            type: ReminderType::LeaseRenewal,
            remindableType: Reminder::LEASE,
            remindableId: $ending->lease->id,
            subject: 'lease:'.$ending->lease->id,
            dueDate: $ending->lease->endDate,
            days: $days,
            leaseId: $ending->lease->id,
        );
    }

    /**
     * The tenant reminders of these rungs, for each rung's tenant who can hear.
     *
     * @param  list<array{Rung, int}>  $rungs
     */
    private function writeForTenants(CarbonImmutable $today, array $rungs): void
    {
        if ($rungs === []) {
            return;
        }

        $tenants = $this->recipients->tenants(array_values(array_unique(array_map(static fn (array $pair): int => $pair[1], $rungs))));

        foreach ($rungs as [$rung, $tenantId]) {
            if (isset($tenants[$tenantId])) {
                $this->write($today, $rung, [$tenants[$tenantId]]);
            }
        }
    }

    /**
     * One pending row per recipient and channel, unless planned already.
     *
     * @param  list<Recipient>  $recipients
     */
    private function write(CarbonImmutable $today, Rung $rung, array $recipients): void
    {
        $rows = [];

        foreach ($recipients as $recipient) {
            foreach ($recipient->channels() as $channel) {
                $rows[$rung->dedupeKey($channel->value, $recipient->key)] = [$recipient, $channel];
            }
        }

        if ($rows === []) {
            return;
        }

        $planned = Reminder::query()->whereIn('dedupe_key', array_keys($rows))->pluck('dedupe_key')->all();

        foreach (array_diff_key($rows, array_flip($planned)) as $key => [$recipient, $channel]) {
            $reminder = new Reminder;
            $reminder->forceFill([
                'type' => $rung->type,
                'remindable_type' => $rung->remindableType,
                'remindable_id' => $rung->remindableId,
                'lease_id' => $rung->leaseId,
                'period_start' => $rung->periodStart?->toDateString(),
                'due_date' => $rung->dueDate->toDateString(),
                'offset_days' => $rung->offset(),
                'send_on' => $today->toDateString(),
                'channel' => $channel,
                'recipient' => $recipient->key,
                'recipient_user_id' => $recipient->userId,
                'recipient_tenant_profile_id' => $recipient->tenantProfileId,
                'recipient_email' => $recipient->email,
                'send_at' => ReminderClock::sendAt($today),
                'status' => ReminderStatus::Pending,
                'dedupe_key' => (string) $key,
                'notification_id' => (string) Str::uuid(),
            ]);

            try {
                $reminder->save();
            } catch (UniqueConstraintViolationException) {
                // Planned by a run at the same time: each reminder once.
                continue;
            }
        }
    }

    /**
     * The days (Y-m-d) whose bills or leases are on one of the ladder's rungs today.
     *
     * @return list<string>
     */
    private function dates(CarbonImmutable $today, ReminderType $type): array
    {
        return array_map(
            fn (int $days): string => $this->calendar->daysAfter($today, $type->countsAfter() ? -$days : $days)->toDateString(),
            $type->rungs(),
        );
    }

    /** The rung of the ladder that $date is on today, if any. */
    private function rungDays(CarbonImmutable $today, ReminderType $type, CarbonImmutable $date): ?int
    {
        foreach ($type->rungs() as $days) {
            if ($this->calendar->daysAfter($today, $type->countsAfter() ? -$days : $days)->toDateString() === $date->toDateString()) {
                return $days;
            }
        }

        return null;
    }

    /**
     * Whether a period from $start to $end takes a due rung of $days: it is
     * at least SHORTEST_PERIOD_DAYS long, and $days is at most half of it.
     * A bill without a period (opening balances) takes every rung.
     */
    private function periodTakes(?CarbonImmutable $start, ?CarbonImmutable $end, int $days): bool
    {
        if ($start === null || $end === null) {
            return true;
        }

        if ($this->calendar->daysAfter($start, self::SHORTEST_PERIOD_DAYS - 1)->greaterThan($end)) {
            return false;
        }

        // Twice the rung fits in the period: start + 2 × days − 1 ≤ end.
        return $days === 0 || $this->calendar->daysAfter($start, 2 * $days - 1)->lessThanOrEqualTo($end);
    }

    private static function leasePeriod(int $leaseId, CarbonImmutable $periodStart): string
    {
        return 'lease:'.$leaseId.'@'.$periodStart->toDateString();
    }
}
