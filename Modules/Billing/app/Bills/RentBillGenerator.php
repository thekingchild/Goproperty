<?php

declare(strict_types=1);

namespace Modules\Billing\Bills;

use App\Documents\BrandingSnapshot;
use App\Documents\BrandingSnapshots;
use App\Tenancy\OrganizationContext;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use LogicException;
use Modules\Billing\Enums\BillableType;
use Modules\Billing\Enums\BillType;
use Modules\Billing\Models\Bill;
use Modules\Leasing\Contracts\BillableLease;
use Modules\Leasing\Contracts\CycleCalculator;
use Modules\Leasing\Contracts\LeaseBillingSchedule;
use Throwable;

/**
 * Rent bills from leases (ARCHITECTURE.md § 6.4), for the current
 * organization: every billable lease (Modules\Leasing\Contracts\
 * LeaseBillingSchedule: active and renewed leases with a period left)
 * whose next period starts within the organization's lead window
 * (`billing.lead_days`, default 7: the bill is generated on
 * CycleCalculator::billingDate(), that many days before the period starts)
 * gets one `rent` bill for that period, and the lease moves on to its next
 * period (LeaseBillingSchedule::advance(), CycleCalculator::nextPeriodStart()).
 * A lease behind by several periods (activated late, or the job did not
 * run) is billed period by period until its next period is outside the
 * window; nothing before the lease's `next_billing_date` is ever billed,
 * so a lease paid until a date (`paid_until`) gets no bill for those
 * periods.
 *
 * Each period is one transaction (DB::transaction($callback, 3)): lock the
 * lease (LeaseBillingSchedule::lock, so runs on the same lease wait for
 * each other), take its next period as it is now, issue the bill
 * (Bills\BillIssuer: number, reference, code, branding snapshot) and
 * advance the lease, together or not at all. The natural key
 * `(lease_id, type, period_start)` is unique in the database too: a re-run,
 * or two runs at once, never bills a period twice (AGENT.md § 3 rule 6).
 *
 * The period's dates and the due date come from the calculator
 * (AGENT.md § 3 rule 3): the period is the calculator's whole period
 * (also when the lease ends inside it: rent is per period, pro-rating is
 * not built), due `due_day_offset` days after it starts.
 */
final readonly class RentBillGenerator
{
    /** Leases read from the schedule at a time. */
    public const int PAGE = 100;

    public function __construct(
        private OrganizationContext $context,
        private LeaseBillingSchedule $schedule,
        private CycleCalculator $calculator,
        private BillIssuer $issuer,
        private BillService $bills,
        private BrandingSnapshots $snapshots,
    ) {}

    /**
     * Bills every rent period due in the current organization today
     * (Lagos). Stops early, returning false, when $shouldStop says so (the
     * job's time budget); a later run carries on where it stopped. A lease
     * that fails is reported and left for the next run; the others go on.
     *
     * @param  (Closure(): bool)|null  $shouldStop
     */
    public function generate(?Closure $shouldStop = null): bool
    {
        $today = BillDates::today();
        $lead = $this->bills->leadDays();
        $branding = null;
        $afterId = 0;

        do {
            $page = $this->schedule->due($today, $lead, $afterId, self::PAGE);

            foreach ($page as $lease) {
                $afterId = $lease->id;
                // Taken once per run, and only when there is something to bill.
                $branding ??= $this->snapshots->take($this->context->get());

                try {
                    if (! $this->billLease($lease->id, $today, $lead, $branding, $shouldStop)) {
                        return false;
                    }
                } catch (Throwable $failure) {
                    report($failure);
                }
            }
        } while (count($page) === self::PAGE);

        return true;
    }

    /**
     * Bills the lease's periods due by today, one transaction each; false
     * when stopped before the lease was done.
     *
     * @param  (Closure(): bool)|null  $shouldStop
     */
    public function billLease(int $leaseId, CarbonImmutable $today, int $lead, BrandingSnapshot $branding, ?Closure $shouldStop = null): bool
    {
        $duplicates = 0;

        while (true) {
            if ($shouldStop !== null && $shouldStop()) {
                return false;
            }

            try {
                $outcome = DB::transaction(fn (): string => $this->billNextPeriod($leaseId, $today, $lead, $branding), 3);
            } catch (UniqueConstraintViolationException $duplicate) {
                if (! str_contains($duplicate->getMessage(), 'bills_lease_type_period_unique') || ++$duplicates > 3) {
                    throw $duplicate;
                }

                // Another run billed this period between our check and our
                // insert (the lease lock makes this all but impossible):
                // nothing was written; look again.
                continue;
            }

            if ($outcome === 'done') {
                return true;
            }
        }
    }

    /**
     * Inside the transaction: 'billed' after billing the lease's next period,
     * 'skipped' when it was billed already (the lease is moved on), 'done'
     * when the lease has nothing (more) to bill today.
     */
    private function billNextPeriod(int $leaseId, CarbonImmutable $today, int $lead, BrandingSnapshot $branding): string
    {
        $lease = $this->schedule->lock($leaseId);

        if (! $lease instanceof BillableLease) {
            return 'done';
        }

        $start = $lease->nextBillingDate;

        if ($this->calculator->billingDate($start, $lead)->greaterThan($today)) {
            return 'done';
        }

        // The lease's row lock is held: this read sees every bill another
        // run committed for the lease before it let go of the lock.
        $billed = Bill::query()
            ->where('lease_id', $lease->id)
            ->where('type', BillType::Rent)
            ->where('period_start', $start->toDateString())
            ->exists();

        if (! $billed) {
            $this->issuer->issue($this->draft($lease, $start), $branding);
        }

        $this->schedule->advance($lease->id, $start);

        return $billed ? 'skipped' : 'billed';
    }

    private function draft(BillableLease $lease, CarbonImmutable $start): BillDraft
    {
        $period = $this->calculator->periodContaining($lease->startDate, $lease->cycle, $start);

        if (! $period->start->isSameDay($start)) {
            throw new LogicException("Lease {$lease->id} is due to bill from {$start->toDateString()}, which is not a period start.");
        }

        $dates = __(':start to :end', ['start' => $period->start->format('d/m/Y'), 'end' => $period->end->format('d/m/Y')]);

        return new BillDraft(
            type: BillType::Rent,
            billableType: BillableType::Lease,
            billableId: $lease->id,
            tenantProfileId: $lease->tenantProfileId,
            description: __('Rent, :dates', ['dates' => $dates]),
            dueDate: $this->calculator->dueDate($period->start, $lease->dueDayOffset),
            lines: [new BillLine(
                label: __('Rent'),
                amount: $lease->rent,
                note: __(':cycle rent, lease :contract', ['cycle' => __($lease->cycle->label()), 'contract' => $lease->contractNumber]),
                periodStart: $period->start,
                periodEnd: $period->end,
                meta: ['cycle' => $lease->cycle->value, 'contract_number' => $lease->contractNumber, 'period_index' => $period->index],
            )],
            leaseId: $lease->id,
            propertyId: $lease->propertyId,
            unitId: $lease->unitId,
            periodStart: $period->start,
            periodEnd: $period->end,
            leaseNumber: $lease->contractNumber,
            cycle: $lease->cycle,
        );
    }
}
