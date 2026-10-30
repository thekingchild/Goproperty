<?php

declare(strict_types=1);

namespace Modules\Reminders\Reminders;

use App\Branding\Branding;
use App\Enums\OrganizationStatus;
use App\Mail\Suppression\Suppressions;
use App\Models\Organization;
use Carbon\CarbonImmutable;
use Modules\Billing\Contracts\RemindableBill;
use Modules\Billing\Contracts\RemindableBills;
use Modules\Leasing\Contracts\BillableLease;
use Modules\Leasing\Contracts\CycleCalculator;
use Modules\Leasing\Contracts\EndingLeases;
use Modules\Leasing\Contracts\LeaseBillingSchedule;
use Modules\Properties\Contracts\UnitDirectory;
use Modules\Reminders\Enums\ReminderChannel;
use Modules\Reminders\Enums\ReminderType;
use Modules\Reminders\Enums\SkipReason;
use Modules\Reminders\Models\Reminder;
use Modules\Tenants\Contracts\TenantDirectory;

/**
 * What a planned reminder says when it is sent, from the records as they
 * are then — or why it is no longer sent (docs/plans/P05-subtasks.md
 * default 7): the bill was paid in full or voided, the lease ended or was
 * terminated (or, for a renewal alert, a renewal was drafted), the
 * recipient can no longer be reached, the organization was suspended
 * (renewal alerts), the email address is on the suppression list (P05-04).
 * Runs in the reminder's organization. The words are GoProperty's email
 * templates (ReminderTemplates); this class gives their values.
 *
 * A rent reminder planned from the lease before its bill existed uses the
 * bill once it is issued (number, amount still owed, payment reference,
 * pay link). Links (default 13): the portal's bill (or lease) page for a
 * tenant with a portal account, else the public pay page once the bill
 * exists; the workspace's lease page for staff.
 */
final readonly class ReminderMessages
{
    public function __construct(
        private RemindableBills $bills,
        private LeaseBillingSchedule $schedule,
        private EndingLeases $ending,
        private CycleCalculator $calendar,
        private UnitDirectory $units,
        private TenantDirectory $tenants,
        private ReminderRecipients $recipients,
        private Suppressions $suppressions,
    ) {}

    public function compose(Reminder $reminder, Organization $organization): ReminderMessage|SkipReason
    {
        $agency = Branding::fromOrganization($organization)->displayName();

        if ($reminder->type === ReminderType::LeaseRenewal) {
            return $this->renewal($reminder, $organization);
        }

        $tenantId = $reminder->recipient_tenant_profile_id;
        $tenant = $tenantId !== null ? ($this->recipients->tenants([$tenantId])[$tenantId] ?? null) : null;

        if ($tenant !== null && in_array($reminder->channel, $tenant->off, true)) {
            return SkipReason::OptedOut;
        }

        if ($tenant === null || ! $this->hears($reminder, $tenant)) {
            return SkipReason::Unreachable;
        }

        if ($reminder->channel === ReminderChannel::Email && $this->suppressions->isSuppressed((string) $reminder->recipient_email)) {
            return SkipReason::Suppressed;
        }

        if ($reminder->remindable_type === Reminder::LEASE) {
            // A rent period planned before its bill: the bill if it exists now.
            $bill = $this->bills->rentFor((int) $reminder->lease_id, (string) $reminder->period_start?->toDateString());

            if ($bill === null) {
                return $this->unbilledRent($reminder, $agency, $tenant->name);
            }
        } else {
            $bill = $this->bills->find($reminder->remindable_id);
        }

        if ($bill === null || ! $bill->open) {
            return SkipReason::Settled;
        }

        return $this->bill($reminder, $bill, $agency, $tenant->name);
    }

    private function bill(Reminder $reminder, RemindableBill $bill, string $agency, string $name): ReminderMessage
    {
        $template = match ($reminder->type) {
            ReminderType::BillOverdue => ReminderTemplates::BILL_OVERDUE,
            ReminderType::RentDue => ReminderTemplates::RENT_DUE,
            default => ReminderTemplates::BILL_DUE,
        };

        return new ReminderMessage(
            template: $template,
            values: [
                'recipient.name' => $name,
                'agency.name' => $agency,
                'unit.place' => self::place($bill->unit, $bill->building),
                'bill.number' => $bill->number,
                'bill.description' => $bill->description,
                'bill.type' => $bill->typeLabel,
                'bill.owed' => $bill->owed->format(),
                'bill.owed_label' => $bill->owed->equals($bill->amount) ? 'Amount due' : 'Amount still owed',
                'bill.period' => self::period($bill->periodStart, $bill->periodEnd),
                'bill.due_date' => self::day($bill->dueDate),
                'bill.due_when' => self::dueWhen($reminder->offset_days, $bill->dueDate),
                'bill.due_since' => self::since($reminder->offset_days, $bill->dueDate),
                'bill.payment_reference' => $bill->paymentReference,
                // For the SMS templates (P05-02): shorter words, the public pay page.
                'bill.due_short' => self::dueShort($reminder->offset_days, $bill->dueDate),
                'bill.due_day' => self::shortDay($bill->dueDate),
                'pay_link' => route('pay.show', ['reference' => $bill->paymentReference]),
            ],
            url: $this->tenantUrl($reminder, $bill),
        );
    }

    /** Rent for a lease period whose bill is not issued yet. */
    private function unbilledRent(Reminder $reminder, string $agency, string $name): ReminderMessage|SkipReason
    {
        $lease = $this->schedule->find((int) $reminder->lease_id);

        if (! $lease instanceof BillableLease || $reminder->period_start === null
            || $lease->nextBillingDate->toDateString() !== $reminder->period_start->toDateString()) {
            // Ended, terminated, or its period is no longer billed.
            return SkipReason::LeaseEnded;
        }

        $unit = $this->units->find($lease->unitId);
        $period = $this->calendar->periodContaining($lease->startDate, $lease->cycle, $lease->nextBillingDate);

        return new ReminderMessage(
            template: ReminderTemplates::RENT_UPCOMING,
            values: [
                'recipient.name' => $name,
                'agency.name' => $agency,
                'unit.place' => self::place($unit?->label, $unit?->propertyName),
                'rent.amount' => $lease->rent->format(),
                'rent.period' => self::period($period->start, $period->end),
                'rent.due_date' => self::day($reminder->due_date),
                'rent.due_when' => self::dueWhen($reminder->offset_days, $reminder->due_date),
                'rent.due_short' => self::dueShort($reminder->offset_days, $reminder->due_date),
            ],
            url: $reminder->recipient_user_id !== null ? route('portal.leases.show', ['lease' => $lease->id]) : null,
        );
    }

    private function renewal(Reminder $reminder, Organization $organization): ReminderMessage|SkipReason
    {
        if ($organization->status === OrganizationStatus::Suspended) {
            return SkipReason::OrganizationInactive;
        }

        $ending = $this->ending->findActive($reminder->remindable_id);

        if ($ending === null || $ending->lease->endDate->toDateString() !== $reminder->due_date->toDateString()) {
            return SkipReason::LeaseEnded;
        }

        if ($ending->renewal !== null) {
            return SkipReason::RenewalDrafted;
        }

        $member = array_values(array_filter($this->recipients->staffFor($ending->lease->propertyId), static fn (Recipient $to): bool => $to->key === $reminder->recipient))[0] ?? null;

        if ($member === null) {
            return SkipReason::Unreachable;
        }

        if (in_array($reminder->channel, $member->off, true)) {
            return SkipReason::OptedOut;
        }

        if ($reminder->channel === ReminderChannel::Email && $reminder->recipient_email !== null && $this->suppressions->isSuppressed($reminder->recipient_email)) {
            return SkipReason::Suppressed;
        }

        $lease = $ending->lease;
        $unit = $this->units->find($lease->unitId);

        return new ReminderMessage(
            template: $ending->autoRenew ? ReminderTemplates::LEASE_ENDING_AUTO_RENEW : ReminderTemplates::LEASE_ENDING,
            values: [
                'recipient.name' => $member->name,
                'agency.name' => Branding::fromOrganization($organization)->displayName(),
                'tenant.name' => $this->tenants->find($lease->tenantProfileId)->name ?? 'the tenant',
                'unit.place' => self::place($unit?->label, $unit?->propertyName),
                'lease.contract_number' => (string) $lease->contractNumber,
                'lease.end_date' => self::day($lease->endDate),
                'lease.ends_when' => self::dueWhen($reminder->offset_days, $lease->endDate),
            ],
            url: route('workspace.leases.show', ['organization' => $organization->slug, 'lease' => $lease->id]),
        );
    }

    /** Whether the tenant can still be reached on the reminder's channel, at the address or number it was planned for. */
    private function hears(Reminder $reminder, Recipient $recipient): bool
    {
        return match ($reminder->channel) {
            ReminderChannel::Email => $reminder->recipient_email !== null,
            ReminderChannel::InApp => $recipient->userId !== null && $recipient->userId === $reminder->recipient_user_id,
            // Still verified, and still the number planned (a changed number is not texted).
            ReminderChannel::Sms => $recipient->phone !== null && $recipient->phone === $reminder->recipient_phone,
            // Still opted in, at the number planned (P05-06): an opt-out counts at once.
            ReminderChannel::WhatsApp => $recipient->whatsapp !== null && $recipient->whatsapp === $reminder->recipient_phone,
        };
    }

    private function tenantUrl(Reminder $reminder, RemindableBill $bill): string
    {
        return $reminder->recipient_user_id !== null
            ? route('portal.bills.show', ['bill' => $bill->id])
            : route('pay.show', ['reference' => $bill->paymentReference]);
    }

    /** "today", "tomorrow" or "in 7 days, on 1 January 2027". */
    private static function dueWhen(int $offset, CarbonImmutable $date): string
    {
        return match (true) {
            $offset === 0 => 'today ('.self::day($date).')',
            $offset === -1 => 'tomorrow ('.self::day($date).')',
            default => 'in '.abs($offset).' days, on '.self::day($date),
        };
    }

    /** For an SMS: "today (1 Jan)", "tomorrow (1 Jan)" or "in 7 days (1 Jan)". */
    private static function dueShort(int $offset, CarbonImmutable $date): string
    {
        return match (true) {
            $offset === 0 => 'today',
            $offset === -1 => 'tomorrow',
            default => 'in '.abs($offset).' days',
        }.' ('.self::shortDay($date).')';
    }

    private static function shortDay(CarbonImmutable $date): string
    {
        return $date->format('j M');
    }

    /** "yesterday (…)" or "on 1 January 2027, 7 days ago". */
    private static function since(int $offset, CarbonImmutable $date): string
    {
        return $offset === 1 ? 'yesterday ('.self::day($date).')' : 'on '.self::day($date).', '.$offset.' days ago';
    }

    private static function period(?CarbonImmutable $start, ?CarbonImmutable $end): string
    {
        return $start !== null && $end !== null ? self::day($start).' to '.self::day($end) : '';
    }

    private static function place(?string $unit, ?string $building): string
    {
        return implode(', ', array_values(array_filter([trim((string) $unit), trim((string) $building)], static fn (string $part): bool => $part !== '')));
    }

    private static function day(CarbonImmutable $date): string
    {
        return $date->format('j F Y');
    }
}
