<?php

declare(strict_types=1);

namespace Modules\SaasBilling\Metering;

use App\Enums\SubscriptionStatus;
use App\Marketing\PlanCatalogue;
use App\Models\Organization;
use App\Models\Subscription;
use App\Subscriptions\SubscriptionService;
use App\Tenancy\OrganizationContext;
use App\Tenancy\OrganizationOwners;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use LogicException;
use Modules\Properties\Contracts\UnitDirectory;
use Modules\SaasBilling\Enums\InvoiceKind;
use Modules\SaasBilling\Enums\InvoiceStatus;
use Modules\SaasBilling\Enums\UnitEventReason;
use Modules\SaasBilling\Invoices\PlatformInvoiceNumbers;
use Modules\SaasBilling\Models\PlatformInvoice;
use Modules\SaasBilling\Models\SubscriptionUnitEvent;
use Modules\SaasBilling\Notifications\PlatformInvoiceIssued;

/**
 * Metering and subscription invoices (P06-02; BILLING_PAYMENTS.md § 1.4,
 * § 3; docs/plans/P06-subtasks.md defaults 13–20). The only writer of
 * subscription invoices and of `subscription_unit_events`. Works on the
 * current organization's subscription (OrganizationContext).
 *
 * billDue() invoices the billing period that is due on a day
 * (BillingPeriod::due()), when the subscription is `active` or `past_due`
 * (§ 3 step 1): it counts the units (Properties' countBillable(), the rule
 * of § 1.4), appends the ledger row (step 2), writes the invoice at the
 * plan's terms in effect on the period's first day, VAT as its own line,
 * due 7 days later (step 3), and rolls the subscription's period through
 * SubscriptionService. All in one transaction under the organization's
 * and the subscription's locks, and the invoice is unique per subscription
 * and period: running it again, or at the same time, bills nothing twice
 * (AGENT.md § 3 rule 6).
 *
 * The owners of the organization (and of its payer, when another) are told
 * once it commits (step 4). A platform-covered organization or payer (§ 2)
 * gets its invoice marked paid at once, audited, and nobody is asked to pay.
 * Charging a card (step 5) is P06-03's; bank transfer P06-04's.
 */
final readonly class SubscriptionMetering
{
    public const string LOG = 'platform_invoices';

    /** The note on a platform-covered organization's invoice, marked paid when issued (§ 2). */
    public const string COVERED_NOTE = 'Covered by GoProperty: marked paid when issued.';

    /** Net 7 (§ 3). */
    public const int DAYS_TO_PAY = 7;

    public function __construct(
        private OrganizationContext $context,
        private SubscriptionService $subscriptions,
        private PlanCatalogue $plans,
        private PlatformInvoiceNumbers $numbers,
        private UnitDirectory $units,
        private OrganizationOwners $owners,
    ) {}

    /** The statuses that are billed (§ 3 step 1). */
    public static function billable(SubscriptionStatus $status): bool
    {
        return in_array($status, [SubscriptionStatus::Active, SubscriptionStatus::PastDue], true);
    }

    /**
     * Invoices the period due on $now (Lagos date), if any; returns the new
     * invoice, or null when nothing was due or it was already invoiced.
     */
    public function billDue(?CarbonImmutable $now = null): ?PlatformInvoice
    {
        $now ??= CarbonImmutable::now();

        return DB::transaction(function () use ($now): ?PlatformInvoice {
            // The same lock order as SubscriptionService: organization, then subscription.
            $organization = Organization::query()->whereKey($this->context->id())->lockForUpdate()->firstOrFail();
            $subscription = Subscription::query()->lockForUpdate()->first();

            if (! $subscription instanceof Subscription || ! self::billable($subscription->status)) {
                return null;
            }

            $period = BillingPeriod::due($subscription, $now, $this->plans->monthsPaidPerYear());

            if ($period === null || $this->invoiced($subscription, $period)) {
                return null;
            }

            $plan = $this->plans->findById($subscription->plan_id, $period->start)
                ?? throw new LogicException("Plan {$subscription->plan_id} has no terms in effect on {$period->start->toDateString()}.");
            $units = $this->units->countBillable();
            $charge = new SubscriptionCharge($plan, $units, $period, $this->plans->vatBasisPoints());
            $payer = $this->payer($subscription, $organization);
            $covered = $organization->platform_covered || $payer->platform_covered;

            $invoice = new PlatformInvoice;
            $invoice->forceFill([
                'number' => $this->numbers->next(),
                'kind' => InvoiceKind::Subscription,
                'subscription_id' => $subscription->id,
                'plan_version_id' => $plan->versionId,
                'plan_name' => mb_substr($plan->name, 0, 60),
                'billing_cycle' => $subscription->billing_cycle,
                'period_start' => $period->start->toDateString(),
                'period_end' => $period->end->toDateString(),
                'units_billed' => $units,
                'included_units' => $plan->includedUnits,
                'extra_units' => $charge->extraUnits(),
                'base_price_kobo' => $plan->base,
                'unit_price_kobo' => $plan->extraUnit,
                'months_billed' => $period->monthsBilled,
                'days_billed' => $period->daysBilled,
                'days_in_month' => $period->daysInMonth,
                'vat_percent' => $this->plans->vatPercent(),
                'subtotal_kobo' => $charge->subtotal(),
                'vat_kobo' => $charge->vat(),
                'total_kobo' => $charge->total(),
                'status' => $covered ? InvoiceStatus::Paid : InvoiceStatus::Open,
                'due_date' => $now->setTimezone('Africa/Lagos')->addDays(self::DAYS_TO_PAY)->toDateString(),
                'paid_at' => $covered ? $now : null,
                'notes' => $covered ? self::COVERED_NOTE : null,
            ])->save();

            $event = $this->record($subscription, $invoice, $plan->versionId, $units, $plan->includedUnits, $charge->extraUnits(), $period);
            $this->subscriptions->recordBilledPeriod($period->start, $period->end, $units);

            activity(self::LOG)
                ->event($covered ? 'platform_invoice.covered' : 'platform_invoice.issued')
                ->performedOn($invoice)
                ->causedByAnonymous()
                ->withProperties([
                    'organization_id' => $organization->id,
                    'invoice' => $invoice->number,
                    'subscription_id' => $subscription->id,
                    'plan' => $plan->key,
                    'plan_version' => $plan->version,
                    'billing_cycle' => $subscription->billing_cycle->value,
                    'period' => ['start' => $period->start->toDateString(), 'end' => $period->end->toDateString()],
                    'units' => $units,
                    'counted_units' => $charge->extraUnits(),
                    'reason' => $event->reason->value,
                    'subtotal_kobo' => $invoice->subtotal_kobo->kobo,
                    'vat_kobo' => $invoice->vat_kobo->kobo,
                    'total_kobo' => $invoice->total_kobo->kobo,
                    'payer_organization_id' => $payer->id,
                ])
                ->log($covered ? "Invoice {$invoice->number} issued and covered by GoProperty" : "Invoice {$invoice->number} issued");

            if (! $covered) {
                $this->tell($invoice, $organization, $payer);
            }

            return $invoice;
        }, 3);
    }

    private function invoiced(Subscription $subscription, BillingPeriod $period): bool
    {
        return PlatformInvoice::query()
            ->where('subscription_id', $subscription->id)
            ->whereDate('period_start', $period->start->toDateString())
            ->exists();
    }

    private function record(Subscription $subscription, PlatformInvoice $invoice, int $versionId, int $units, int $included, int $counted, BillingPeriod $period): SubscriptionUnitEvent
    {
        $last = SubscriptionUnitEvent::query()
            ->where('subscription_id', $subscription->id)
            ->orderByDesc('effective_date')
            ->first();

        $reason = match (true) {
            ! $last instanceof SubscriptionUnitEvent => UnitEventReason::Initial,
            $last->plan_version_id !== $versionId => UnitEventReason::PlanChange,
            $units > $last->unit_count => UnitEventReason::UnitAdded,
            $units < $last->unit_count => UnitEventReason::UnitRemoved,
            default => UnitEventReason::Unchanged,
        };

        $event = new SubscriptionUnitEvent;
        $event->forceFill([
            'subscription_id' => $subscription->id,
            'platform_invoice_id' => $invoice->id,
            'plan_version_id' => $versionId,
            'effective_date' => $period->start->toDateString(),
            'unit_count' => $units,
            'included_units' => $included,
            'counted_units' => $counted,
            'reason' => $reason,
        ])->save();

        return $event;
    }

    private function payer(Subscription $subscription, Organization $organization): Organization
    {
        if ($subscription->payerId() === $organization->id) {
            return $organization;
        }

        return Organization::query()->find($subscription->payerId()) ?? $organization;
    }

    /** The owners of the organization and of its payer, once each; queued after the commit. */
    private function tell(PlatformInvoice $invoice, Organization $organization, Organization $payer): void
    {
        $recipients = $this->owners->owners($organization);

        if ($payer->id !== $organization->id) {
            $recipients = $recipients->merge($this->owners->owners($payer))->unique('id');
        }

        foreach ($recipients as $recipient) {
            $recipient->notify(new PlatformInvoiceIssued($organization->id, $invoice->id));
        }
    }
}
