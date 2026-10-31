<?php

declare(strict_types=1);

namespace Modules\SaasBilling\Dunning;

use App\Enums\SubscriptionStatus;
use App\Mail\Templates\EmailTemplates;
use App\Models\Organization;
use App\Models\Subscription;
use App\Models\User;
use App\Sms\SmsOutbox;
use App\Subscriptions\DunningSchedule;
use App\Subscriptions\SubscriptionService;
use App\Support\Money;
use App\Tenancy\OrganizationContext;
use App\Tenancy\OrganizationOwners;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\SaasBilling\Enums\DunningStep;
use Modules\SaasBilling\Enums\InvoiceKind;
use Modules\SaasBilling\Enums\InvoiceStatus;
use Modules\SaasBilling\Models\DunningNotice;
use Modules\SaasBilling\Models\PlatformInvoice;
use Modules\SaasBilling\Notifications\SubscriptionOverdue;

/**
 * The dunning ladder (P06-05; BILLING_PAYMENTS.md § 6; dates from Core's
 * App\Subscriptions\DunningSchedule): moves the current organization's
 * unpaid subscription along `active → past_due → grace → suspended →
 * cancelled` through SubscriptionService (AGENT.md § 3 rule 5), and tells
 * its owners (and its payer's) at each step:
 *
 * - an invoice open past its due date: `past_due`, email and in-app notice;
 * - 7 days later: `grace`, and a warning every day until suspension;
 * - 7 days after that: `suspended` (the organization is read-only through
 *   its status, ARCHITECTURE.md § 4), email, in-app notice and an SMS to
 *   each owner's verified phone, paid by GoProperty (the product owner's
 *   decision of 2026-10-31: never from the organization's SMS credits);
 * - every 14 days suspended: the same again as a reminder;
 * - 90 days suspended: `cancelled` (data kept; the NDPA tooling erases it
 *   later), email and in-app notice.
 *
 * Paying is what stops it: Payments\Reactivation makes a lapsed
 * subscription active again once nothing is overdue. Should that ever be
 * missed (two invoices paid at the same moment each see the other still
 * open), a lapsed subscription with nothing overdue is made active here.
 * Platform-covered organizations and payers are never dunned (§ 2).
 *
 * One step a run, under the organization's and the subscription's locks:
 * each notice is a `dunning_notices` row written in the same transaction
 * as the move, unique per subscription, step and day, so the hourly job
 * moves and tells once however often or concurrently it runs (AGENT.md
 * § 3 rule 6). Notices are queued after the commit.
 */
final readonly class DunningLadder
{
    public const string SMS_SOURCE = 'dunning_notice';

    public function __construct(
        private SubscriptionService $subscriptions,
        private OrganizationContext $context,
        private OrganizationOwners $owners,
        private SmsOutbox $sms,
        private EmailTemplates $templates,
    ) {}

    /** The notice sent this run, or null when nothing was due. */
    public function run(?CarbonImmutable $now = null): ?DunningNotice
    {
        $today = DunningSchedule::day($now ?? CarbonImmutable::now());

        return DB::transaction(function () use ($today): ?DunningNotice {
            $organization = Organization::query()->whereKey($this->context->id())->lockForUpdate()->firstOrFail();
            $subscription = Subscription::query()->lockForUpdate()->first();

            if (! $subscription instanceof Subscription || $this->covered($subscription, $organization)) {
                return null;
            }

            $overdue = PlatformInvoice::query()
                ->where('kind', InvoiceKind::Subscription)
                ->where('status', InvoiceStatus::Open)
                ->whereDate('due_date', '<', $today->toDateString())
                ->orderBy('due_date')
                ->orderBy('id')
                ->get();

            if ($overdue->isEmpty()) {
                if (in_array($subscription->status, [SubscriptionStatus::PastDue, SubscriptionStatus::Grace, SubscriptionStatus::Suspended], true)) {
                    $this->subscriptions->activate(null, 'Nothing is overdue any more');
                }

                return null;
            }

            return match ($subscription->status) {
                SubscriptionStatus::Active => $this->pastDue($overdue, $today),
                SubscriptionStatus::PastDue => $this->grace($subscription, $overdue, $today),
                SubscriptionStatus::Grace => $this->suspension($subscription, $overdue, $today),
                SubscriptionStatus::Suspended => $this->suspended($subscription, $overdue, $today),
                default => null,
            };
        }, 3);
    }

    /** @param  Collection<int, PlatformInvoice>  $overdue */
    private function pastDue(Collection $overdue, CarbonImmutable $today): ?DunningNotice
    {
        $first = $overdue->firstOrFail();
        $subscription = $this->subscriptions->markPastDue("Invoice {$first->number} is unpaid past its due date, {$first->due_date?->format('j F Y')}");

        return $this->notify($subscription, DunningStep::PastDue, $today, $overdue, DunningSchedule::suspensionDate($subscription));
    }

    /** @param  Collection<int, PlatformInvoice>  $overdue */
    private function grace(Subscription $subscription, Collection $overdue, CarbonImmutable $today): ?DunningNotice
    {
        $graceDate = DunningSchedule::graceDate($subscription);

        if ($graceDate === null || $today->lessThan($graceDate)) {
            return null;
        }

        $subscription = $this->subscriptions->startGrace('Unpaid '.DunningSchedule::GRACE_AFTER_DAYS.' days past due: the final days before suspension');

        return $this->notify($subscription, DunningStep::GraceWarning, $today, $overdue, DunningSchedule::suspensionDate($subscription));
    }

    /**
     * In grace: suspended once it is over, else today's warning.
     *
     * @param  Collection<int, PlatformInvoice>  $overdue
     */
    private function suspension(Subscription $subscription, Collection $overdue, CarbonImmutable $today): ?DunningNotice
    {
        $suspensionDate = DunningSchedule::suspensionDate($subscription);

        if ($suspensionDate !== null && $today->greaterThanOrEqualTo($suspensionDate)) {
            $subscription = $this->subscriptions->suspend(null, 'Unpaid beyond the grace period');

            return $this->notify($subscription, DunningStep::Suspended, $today, $overdue, DunningSchedule::cancellationDate($subscription));
        }

        return $this->notify($subscription, DunningStep::GraceWarning, $today, $overdue, $suspensionDate);
    }

    /**
     * Suspended: cancelled after 90 days, else a reminder every 14 days.
     *
     * @param  Collection<int, PlatformInvoice>  $overdue
     */
    private function suspended(Subscription $subscription, Collection $overdue, CarbonImmutable $today): ?DunningNotice
    {
        if ($subscription->suspended_at === null) {
            return null;
        }

        $cancellationDate = DunningSchedule::cancellationDate($subscription);

        if ($cancellationDate !== null && $today->greaterThanOrEqualTo($cancellationDate)) {
            $subscription = $this->subscriptions->cancel(null, 'Unpaid '.DunningSchedule::CANCEL_AFTER_DAYS.' days after suspension');

            return $this->notify($subscription, DunningStep::Cancelled, $today, $overdue, null);
        }

        $suspendedOn = DunningSchedule::day($subscription->suspended_at);
        $reminders = intdiv((int) $suspendedOn->diffInDays($today), DunningSchedule::REMIND_EVERY_DAYS);

        if ($reminders < 1) {
            return null;
        }

        // The latest reminder that fell due: one missed while the job was down is sent once, late.
        return $this->notify($subscription, DunningStep::SuspensionReminder, $suspendedOn->addDays($reminders * DunningSchedule::REMIND_EVERY_DAYS), $overdue, $cancellationDate);
    }

    /**
     * Writes the notice (once per subscription, step and day) and queues it
     * to the owners after the commit: in the app, by email, and by SMS for
     * suspension.
     *
     * @param  Collection<int, PlatformInvoice>  $overdue
     */
    private function notify(Subscription $subscription, DunningStep $step, CarbonImmutable $day, Collection $overdue, ?CarbonImmutable $nextStepOn): ?DunningNotice
    {
        $exists = DunningNotice::query()
            ->where('subscription_id', $subscription->id)
            ->where('step', $step)
            ->whereDate('notice_date', $day->toDateString())
            ->exists();

        if ($exists) {
            return null;
        }

        $total = Money::sum($overdue->map(static fn (PlatformInvoice $invoice): Money => $invoice->total_kobo));
        $notice = new DunningNotice;
        $notice->forceFill([
            'subscription_id' => $subscription->id,
            'step' => $step,
            'notice_date' => $day->toDateString(),
            'next_step_on' => $nextStepOn?->toDateString(),
            'overdue_kobo' => $total->kobo,
            'invoice_numbers' => $overdue->pluck('number')->values()->all(),
        ])->save();

        $recipients = $this->recipients($subscription);
        $texted = 0;

        foreach ($recipients as $recipient) {
            $recipient->notify(new SubscriptionOverdue($subscription->organization_id, $notice->id));

            if ($step->sendsSms() && $recipient->hasVerifiedPhone()) {
                $this->text($notice, $step, $recipient, $total, $nextStepOn);
                $texted++;
            }
        }

        $notice->forceFill(['recipients' => $recipients->count(), 'sms' => $texted])->save();

        return $notice;
    }

    /** GoProperty's SMS to an owner (§ 6), paid by GoProperty: no SMS credit is taken. */
    private function text(DunningNotice $notice, DunningStep $step, User $recipient, Money $total, ?CarbonImmutable $nextStepOn): void
    {
        $this->sms->queue(
            type: $step->template(),
            to: (string) $recipient->phone,
            text: $this->templates->renderSms((string) $step->smsTemplate(), [
                'organization.name' => $this->context->get()->name,
                'amount.overdue' => $total->format(),
                'closing.date' => $nextStepOn?->format('j M Y'),
            ]),
            dedupeKey: "dunning:{$notice->id}:{$recipient->id}",
            userId: $recipient->id,
            sourceType: self::SMS_SOURCE,
            sourceId: $notice->id,
            paidByPlatform: true,
        );
    }

    /**
     * The owners of the organization and of its payer, once each (as its
     * invoices are sent, P06-02).
     *
     * @return Collection<int, User>
     */
    private function recipients(Subscription $subscription): Collection
    {
        $organization = $this->context->get();
        $recipients = $this->owners->owners($organization);

        if ($subscription->payerId() !== $organization->id) {
            $payer = Organization::query()->find($subscription->payerId());

            if ($payer instanceof Organization) {
                $recipients = $recipients->merge($this->owners->owners($payer))->unique('id')->values();
            }
        }

        return $recipients;
    }

    /** § 2: platform-covered organizations and payers skip dunning. */
    private function covered(Subscription $subscription, Organization $organization): bool
    {
        if ($organization->platform_covered) {
            return true;
        }

        return $subscription->payerId() !== $organization->id
            && (bool) Organization::query()->whereKey($subscription->payerId())->value('platform_covered');
    }
}
