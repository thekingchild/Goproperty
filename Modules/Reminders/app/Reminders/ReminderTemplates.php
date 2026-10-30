<?php

declare(strict_types=1);

namespace Modules\Reminders\Reminders;

use App\Mail\Templates\Placeholder;
use App\Mail\Templates\TemplateCategory;
use App\Mail\Templates\TemplateContent;
use App\Mail\Templates\TemplateDefinition;

/**
 * The reminders' email templates (P05-04; docs/plans/P05-subtasks.md
 * default 16): one per reminder type and kind of rung, seeded with
 * P05-01's wording. GoProperty's `content-manager` changes the words in
 * the console; agencies change only what the placeholders fill in (their
 * name, through their branding). ReminderMessages gives the values.
 * The in-app notice shows the subject.
 */
final class ReminderTemplates
{
    public const string RENT_DUE = 'reminder.rent_due';

    public const string RENT_UPCOMING = 'reminder.rent_upcoming';

    public const string BILL_DUE = 'reminder.bill_due';

    public const string BILL_OVERDUE = 'reminder.bill_overdue';

    public const string LEASE_ENDING = 'reminder.lease_ending';

    public const string LEASE_ENDING_AUTO_RENEW = 'reminder.lease_ending_auto_renew';

    private const string GREETING = "Hello {{ recipient.name }},\n\n";

    private const string REFERENCE = 'Put the payment reference {{ bill.payment_reference }} in your transfer narration so {{ agency.name }} can match your payment.';

    private const string PAID = 'If you have paid already, you can ignore this message.';

    private const string SIGN_OFF = "\n\n{{ agency.name }}";

    /** @return list<TemplateDefinition> */
    public static function definitions(): array
    {
        $tenant = [
            new Placeholder('recipient.name', "The tenant's name", 'Femi Okon'),
            new Placeholder('agency.name', "The agency's name (its trading name when it has one)", 'Adeyemi Realty'),
            new Placeholder('unit.place', 'The unit and building, e.g. "Flat 3B, Palm Court"', 'Flat 3B, Palm Court'),
        ];
        $bill = [
            new Placeholder('bill.number', "The bill's (invoice's) number", 'INV-AR-000123'),
            new Placeholder('bill.description', "The bill's description", 'Electricity, October 2027'),
            new Placeholder('bill.type', 'What kind of bill it is, e.g. "Electricity"', 'Electricity'),
            new Placeholder('bill.owed', 'The amount still owed', '₦1,500,000'),
            new Placeholder('bill.owed_label', '"Amount due", or "Amount still owed" after a part payment', 'Amount due'),
            new Placeholder('bill.period', 'The period the bill is for, e.g. "1 January 2027 to 31 December 2027"', '1 January 2027 to 31 December 2027'),
            new Placeholder('bill.due_date', 'The due date, e.g. "1 January 2027"', '1 January 2027'),
            new Placeholder('bill.payment_reference', 'The reference the tenant puts in the transfer narration', 'GP-7K3M9Q'),
        ];
        $due = new Placeholder('bill.due_when', 'When it is due: "today (…)", "tomorrow (…)" or "in 7 days, on 1 January 2027"', 'in 7 days, on 1 January 2027');
        $since = new Placeholder('bill.due_since', 'When it was due: "yesterday (…)" or "on 1 January 2027, 7 days ago"', 'on 1 January 2027, 7 days ago');
        $staff = [
            new Placeholder('recipient.name', "The staff member's name", 'Bola Ade'),
            new Placeholder('agency.name', "The agency's name", 'Adeyemi Realty'),
            new Placeholder('tenant.name', "The tenant's name", 'Kemi Adebayo'),
            new Placeholder('unit.place', 'The unit and building', 'Flat 3B, Palm Court'),
            new Placeholder('lease.contract_number', "The lease's contract number", 'LS-AR-000045'),
            new Placeholder('lease.end_date', "The lease's last day", '31 December 2027'),
            new Placeholder('lease.ends_when', 'When it ends: "in 60 days, on 31 December 2027", "tomorrow (…)"', 'in 60 days, on 31 December 2027'),
        ];
        $endsLine = 'The lease of {{ tenant.name }} for {{ unit.place }} ({{ lease.contract_number }}) ends {{ lease.ends_when }}.';

        return [
            new TemplateDefinition(
                key: self::RENT_DUE,
                name: 'Rent due',
                description: 'To the tenant on the rent ladder (T−30 … T−0) once the rent bill is issued.',
                category: TemplateCategory::Reminder,
                placeholders: [...$tenant, ...$bill, $due],
                content: new TemplateContent(
                    subject: 'Rent of {{ bill.owed }} due {{ bill.due_when }}: {{ unit.place }}',
                    body: self::GREETING
                        ."Your rent for {{ unit.place }} ({{ bill.period }}) is due {{ bill.due_when }}.\n\n"
                        ."{{ bill.owed_label }}: {{ bill.owed }} (invoice {{ bill.number }}).\n\n"
                        .self::REFERENCE."\n\n".self::PAID.self::SIGN_OFF,
                    actionLabel: 'Pay or send your proof',
                ),
            ),
            new TemplateDefinition(
                key: self::RENT_UPCOMING,
                name: 'Rent due (before the bill)',
                description: "To the tenant on rent rungs that come before the bill is issued (T−30, T−14 with the default 7-day lead): the lease's next period, no payment details yet.",
                category: TemplateCategory::Reminder,
                placeholders: [
                    ...$tenant,
                    new Placeholder('rent.amount', 'The rent for the period', '₦1,500,000'),
                    new Placeholder('rent.period', 'The period, e.g. "1 January 2027 to 31 December 2027"', '1 January 2027 to 31 December 2027'),
                    new Placeholder('rent.due_date', 'The due date', '1 January 2027'),
                    new Placeholder('rent.due_when', 'When it is due: "in 30 days, on 1 January 2027"', 'in 30 days, on 1 January 2027'),
                ],
                content: new TemplateContent(
                    subject: 'Rent of {{ rent.amount }} due {{ rent.due_when }}: {{ unit.place }}',
                    body: self::GREETING
                        ."Your next rent for {{ unit.place }} ({{ rent.period }}) of {{ rent.amount }} is due {{ rent.due_when }}.\n\n"
                        .'{{ agency.name }} will send you the invoice with the payment details before then.'.self::SIGN_OFF,
                    actionLabel: 'Open your lease',
                ),
            ),
            new TemplateDefinition(
                key: self::BILL_DUE,
                name: 'Utility bill due',
                description: 'To the tenant on the utility ladder (T−3, T−0).',
                category: TemplateCategory::Reminder,
                placeholders: [...$tenant, ...$bill, $due],
                content: new TemplateContent(
                    subject: '{{ bill.type }} of {{ bill.owed }} due {{ bill.due_when }}: {{ unit.place }}',
                    body: self::GREETING
                        ."Your bill {{ bill.number }} ({{ bill.description }}) is due {{ bill.due_when }}.\n\n"
                        ."{{ bill.owed_label }}: {{ bill.owed }} (invoice {{ bill.number }}).\n\n"
                        .self::REFERENCE."\n\n".self::PAID.self::SIGN_OFF,
                    actionLabel: 'Pay or send your proof',
                ),
            ),
            new TemplateDefinition(
                key: self::BILL_OVERDUE,
                name: 'Bill overdue',
                description: 'To the tenant on the overdue ladder (+1, +7, +14), for rent and every other bill still owing.',
                category: TemplateCategory::Reminder,
                placeholders: [...$tenant, ...$bill, $since],
                content: new TemplateContent(
                    subject: 'Overdue: {{ bill.owed }} still owed on bill {{ bill.number }}',
                    body: self::GREETING
                        ."Your bill {{ bill.number }} ({{ bill.description }}) was due {{ bill.due_since }}, and {{ bill.owed }} is still owed.\n\n"
                        .self::REFERENCE."\n\n"
                        .'Contact {{ agency.name }} if you need to talk about it. '.self::PAID.self::SIGN_OFF,
                    actionLabel: 'Pay or send your proof',
                ),
            ),
            new TemplateDefinition(
                key: self::LEASE_ENDING,
                name: 'Lease ending (to staff)',
                description: "To the building's agents (else owners and administrators) on the renewal ladder (T−60, T−30, T−7) when no renewal is drafted.",
                category: TemplateCategory::Reminder,
                placeholders: $staff,
                content: new TemplateContent(
                    subject: 'Lease ends {{ lease.ends_when }}: {{ unit.place }}',
                    body: self::GREETING.$endsLine."\n\n"
                        .'No renewal has been drafted yet. Draft one from the lease page, or let the tenant know the lease will end.'.self::SIGN_OFF,
                    actionLabel: 'Open the lease',
                ),
            ),
            new TemplateDefinition(
                key: self::LEASE_ENDING_AUTO_RENEW,
                name: 'Lease ending, renews by itself (to staff)',
                description: 'As "Lease ending", for a lease set to renew automatically.',
                category: TemplateCategory::Reminder,
                placeholders: $staff,
                content: new TemplateContent(
                    subject: 'Lease ends {{ lease.ends_when }}: {{ unit.place }}',
                    body: self::GREETING.$endsLine."\n\n"
                        .'It is set to renew automatically, so it rolls on by itself unless you change it.'.self::SIGN_OFF,
                    actionLabel: 'Open the lease',
                ),
            ),
        ];
    }
}
