<?php

declare(strict_types=1);

namespace Modules\Reminders\Reminders;

use App\Mail\Templates\TemplateDefinition;
use App\Notifications\Channels\WhatsAppEvent;
use Modules\Reminders\Enums\ReminderType;

/**
 * The reminders as WhatsApp events (P05-06; ARCHITECTURE.md § 6.7): one per
 * kind of message and rung, `reminder.rent_due.t7` style, so the agency
 * picks a template, and the channel order, rung by rung:
 *
 * - `reminder.rent_due.t{n}`: rent with its bill issued;
 * - `reminder.rent_upcoming.t{n}`: rent for a lease period before its bill
 *   exists (no amount owed, reference or pay link yet);
 * - `reminder.utility_due.t{n}`: a utility bill;
 * - `reminder.bill_overdue.p{n}`: any bill n days after its due date.
 *
 * Each event's placeholders are those of the email template and its SMS
 * version (ReminderTemplates), so a WhatsApp template is filled in from
 * the very values the other channels use. Renewal alerts go to staff and
 * have no WhatsApp event.
 */
final class ReminderEvents
{
    /** The event of a rung: before the bill exists ($beforeBill) a rent rung is "rent upcoming". */
    public static function key(ReminderType $type, int $days, bool $beforeBill = false): string
    {
        $kind = $type === ReminderType::RentDue && $beforeBill ? 'rent_upcoming' : $type->value;

        return 'reminder.'.$kind.'.'.($type->countsAfter() ? 'p' : 't').$days;
    }

    /** The event of a message about to be sent: its template says whether the bill exists yet. */
    public static function forTemplate(ReminderType $type, int $days, string $template): string
    {
        return self::key($type, $days, $template === ReminderTemplates::RENT_UPCOMING);
    }

    /** @return list<WhatsAppEvent> */
    public static function definitions(): array
    {
        $templates = [];

        foreach (ReminderTemplates::definitions() as $definition) {
            $templates[$definition->key] = $definition;
        }

        $kinds = [
            [ReminderType::RentDue, false, ReminderTemplates::RENT_DUE, 'Rent reminders', 'Rent due (bill issued)'],
            [ReminderType::RentDue, true, ReminderTemplates::RENT_UPCOMING, 'Rent reminders', 'Rent due (before the bill is issued)'],
            [ReminderType::UtilityDue, false, ReminderTemplates::BILL_DUE, 'Utility reminders', 'Utility bill due'],
            [ReminderType::BillOverdue, false, ReminderTemplates::BILL_OVERDUE, 'Overdue reminders', 'Bill overdue'],
        ];
        $events = [];

        foreach ($kinds as [$type, $beforeBill, $template, $group, $label]) {
            $placeholders = WhatsAppEvent::merged(array_map(
                static fn (?TemplateDefinition $definition): array => $definition->placeholders ?? [],
                [$templates[$template] ?? null, $templates[ReminderTemplates::sms($template)] ?? null],
            ));

            foreach ($type->rungs() as $days) {
                $events[] = new WhatsAppEvent(
                    key: self::key($type, $days, $beforeBill),
                    group: $group,
                    label: $label.', '.self::when($type, $days),
                    placeholders: $placeholders,
                    fallsBackToSms: true,
                    smsRung: in_array($days, $type->smsRungs(), true),
                    order: 10,
                );
            }
        }

        return $events;
    }

    private static function when(ReminderType $type, int $days): string
    {
        if ($type->countsAfter()) {
            return $days === 1 ? '1 day after the due date' : $days.' days after the due date';
        }

        return match ($days) {
            0 => 'on the due date',
            1 => '1 day before',
            default => $days.' days before',
        };
    }
}
