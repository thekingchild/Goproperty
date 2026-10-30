<?php

declare(strict_types=1);

namespace Modules\Reminders\Reminders;

use App\Branding\Branding;
use App\Enums\OrganizationStatus;
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
 * (renewal alerts). Runs in the reminder's organization.
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
    ) {}

    public function compose(Reminder $reminder, Organization $organization): ReminderMessage|SkipReason
    {
        $agency = Branding::fromOrganization($organization)->displayName();

        if ($reminder->type === ReminderType::LeaseRenewal) {
            return $this->renewal($reminder, $organization);
        }

        $tenant = $this->stillHears($reminder);

        if ($tenant === null) {
            return SkipReason::Unreachable;
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
        $place = self::place($bill->unit, $bill->building);
        $owed = $bill->owed->format();
        $what = match ($reminder->type) {
            ReminderType::RentDue => 'Your rent'.($place !== '' ? ' for '.$place : '').self::period($bill->periodStart, $bill->periodEnd),
            default => 'Your bill '.$bill->number.' ('.$bill->description.')',
        };
        $reference = 'Put the payment reference '.$bill->paymentReference.' in your transfer narration so '.$agency.' can match your payment.';
        $paid = 'If you have paid already, you can ignore this message.';

        if ($reminder->type === ReminderType::BillOverdue) {
            return new ReminderMessage(
                subject: 'Overdue: '.$owed.' still owed on bill '.$bill->number,
                lines: [
                    $what.' was due '.self::since($reminder->offset_days, $bill->dueDate).', and '.$owed.' is still owed.',
                    $reference,
                    'Contact '.$agency.' if you need to talk about it. '.$paid,
                ],
                url: $this->tenantUrl($reminder, $bill),
                actionLabel: 'Pay or send your proof',
                greetingName: $name,
            );
        }

        $label = $reminder->type === ReminderType::RentDue ? 'Rent' : $bill->typeLabel;

        return new ReminderMessage(
            subject: $label.' of '.$owed.' due '.self::dueWhen($reminder->offset_days, $bill->dueDate).($place !== '' ? ': '.$place : ''),
            lines: [
                $what.' is due '.self::dueWhen($reminder->offset_days, $bill->dueDate).'.',
                'Amount '.($bill->owed->equals($bill->amount) ? 'due' : 'still owed').': '.$owed.' (invoice '.$bill->number.').',
                $reference,
                $paid,
            ],
            url: $this->tenantUrl($reminder, $bill),
            actionLabel: 'Pay or send your proof',
            greetingName: $name,
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
        $place = self::place($unit?->label, $unit?->propertyName);
        $when = self::dueWhen($reminder->offset_days, $reminder->due_date);

        return new ReminderMessage(
            subject: 'Rent of '.$lease->rent->format().' due '.$when.($place !== '' ? ': '.$place : ''),
            lines: [
                'Your next rent'.($place !== '' ? ' for '.$place : '').self::period($period->start, $period->end).' of '.$lease->rent->format().' is due '.$when.'.',
                $agency.' will send you the invoice with the payment details before then.',
            ],
            url: $reminder->recipient_user_id !== null ? route('portal.leases.show', ['lease' => $lease->id]) : null,
            actionLabel: 'Open your lease',
            greetingName: $name,
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

        $lease = $ending->lease;
        $unit = $this->units->find($lease->unitId);
        $place = self::place($unit?->label, $unit?->propertyName);
        $tenant = $this->tenants->find($lease->tenantProfileId)->name ?? 'the tenant';
        $contract = $lease->contractNumber !== null ? ' ('.$lease->contractNumber.')' : '';
        $when = self::dueWhen($reminder->offset_days, $lease->endDate);

        return new ReminderMessage(
            subject: 'Lease ends '.$when.($place !== '' ? ': '.$place : ''),
            lines: [
                'The lease of '.$tenant.($place !== '' ? ' for '.$place : '').$contract.' ends '.$when.'.',
                $ending->autoRenew
                    ? 'It is set to renew automatically, so it rolls on by itself unless you change it.'
                    : 'No renewal has been drafted yet. Draft one from the lease page, or let the tenant know the lease will end.',
            ],
            url: route('workspace.leases.show', ['organization' => $organization->slug, 'lease' => $lease->id]),
            actionLabel: 'Open the lease',
            greetingName: $member->name,
        );
    }

    /** The tenant, when they can still be reached on the reminder's channel. */
    private function stillHears(Reminder $reminder): ?Recipient
    {
        $tenantId = $reminder->recipient_tenant_profile_id;
        $recipient = $tenantId !== null ? ($this->recipients->tenants([$tenantId])[$tenantId] ?? null) : null;

        $hears = $recipient !== null && ($reminder->channel === ReminderChannel::Email
            ? $reminder->recipient_email !== null
            : $recipient->userId !== null && $recipient->userId === $reminder->recipient_user_id);

        return $hears ? $recipient : null;
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

    /** "yesterday (…)" or "on 1 January 2027, 7 days ago". */
    private static function since(int $offset, CarbonImmutable $date): string
    {
        return $offset === 1 ? 'yesterday ('.self::day($date).')' : 'on '.self::day($date).', '.$offset.' days ago';
    }

    private static function period(?CarbonImmutable $start, ?CarbonImmutable $end): string
    {
        return $start !== null && $end !== null ? ' ('.self::day($start).' to '.self::day($end).')' : '';
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
