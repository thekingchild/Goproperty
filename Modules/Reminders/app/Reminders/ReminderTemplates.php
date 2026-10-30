<?php

declare(strict_types=1);

namespace Modules\Reminders\Reminders;

use App\Mail\Templates\Placeholder;
use App\Mail\Templates\TemplateCategory;
use App\Mail\Templates\TemplateChannel;
use App\Mail\Templates\TemplateContent;
use App\Mail\Templates\TemplateDefinition;

/**
 * The reminders' email templates (P05-04; docs/plans/P05-subtasks.md
 * default 16): one per reminder type and kind of rung, seeded with
 * P05-01's wording. GoProperty's `content-manager` changes the words in
 * the console; agencies change only what the placeholders fill in (their
 * name, through their branding). ReminderMessages gives the values.
 * The in-app notice shows the subject.
 *
 * SMS (P05-02): the bill reminders also have an SMS version (key + `.sms`),
 * plain GSM text that fits one page with every value at its longest
 * (lengths below; the link's from APP_URL). Renewal alerts go to staff and
 * have none.
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

    /** The SMS version of an email template's key. */
    public static function sms(string $key): string
    {
        return $key.'.sms';
    }

    /** @return list<TemplateDefinition> */
    public static function definitions(): array
    {
        return [...self::emails(), ...self::texts()];
    }

    /** @return list<TemplateDefinition> */
    private static function texts(): array
    {
        $agency = new Placeholder('agency.name', "The agency's name (cut to 24 characters)", 'Adeyemi Realty', 24);
        $owed = new Placeholder('bill.owed', 'The amount still owed, e.g. "NGN 1,500,000"', 'NGN 1,500,000', 18);
        $reference = new Placeholder('bill.payment_reference', 'The reference for the transfer narration', 'GP-7K3M9Q', 16);
        $link = new Placeholder('pay_link', 'The public pay page for the bill', rtrim((string) config('app.url'), '/').'/pay/GP-7K3M9Q', static fn (): int => strlen(route('pay.show', ['reference' => str_repeat('X', 16)])));
        $dueShort = new Placeholder('bill.due_short', 'When it is due: "today (1 Jan)", "tomorrow (1 Jan)", "in 7 days (1 Jan)"', 'in 7 days (1 Jan)', 19);

        return [
            self::text(self::RENT_DUE, 'Rent due', 'By SMS on rent T−7 and T−1, to a verified number.', [$agency, $owed, $dueShort, $reference, $link],
                '{{ agency.name }}: rent of {{ bill.owed }} is due {{ bill.due_short }}. Pay to ref {{ bill.payment_reference }}: {{ pay_link }}'),
            self::text(self::RENT_UPCOMING, 'Rent due (before the bill)', 'By SMS on a rent SMS rung that comes before the bill is issued.', [
                $agency,
                new Placeholder('rent.amount', 'The rent for the period', 'NGN 1,500,000', 18),
                new Placeholder('rent.due_short', 'When it is due: "in 7 days (1 Jan)"', 'in 7 days (1 Jan)', 19),
            ], '{{ agency.name }}: your rent of {{ rent.amount }} is due {{ rent.due_short }}. Your invoice with payment details follows.'),
            self::text(self::BILL_DUE, 'Utility bill due', 'By SMS on utility T−0, to a verified number.', [
                $agency,
                new Placeholder('bill.type', 'What kind of bill, e.g. "Electricity" (cut to 14 characters)', 'Electricity', 14),
                $owed, $dueShort, $reference, $link,
            ], '{{ agency.name }}: {{ bill.type }} of {{ bill.owed }} due {{ bill.due_short }}. Ref {{ bill.payment_reference }}: {{ pay_link }}'),
            self::text(self::BILL_OVERDUE, 'Bill overdue', 'By SMS on overdue +1 and +7, to a verified number.', [
                $agency, $owed,
                new Placeholder('bill.due_day', 'The due date, e.g. "1 Jan"', '1 Jan', 6),
                $reference, $link,
            ], '{{ agency.name }}: {{ bill.owed }} is overdue (due {{ bill.due_day }}). Ref {{ bill.payment_reference }}: {{ pay_link }}'),
        ];
    }

    /** @param  list<Placeholder>  $placeholders */
    private static function text(string $key, string $name, string $description, array $placeholders, string $body): TemplateDefinition
    {
        return new TemplateDefinition(
            key: self::sms($key),
            name: $name.' (SMS)',
            description: $description,
            category: TemplateCategory::Reminder,
            placeholders: $placeholders,
            content: new TemplateContent('', $body),
            channel: TemplateChannel::Sms,
        );
    }

    /** @return list<TemplateDefinition> */
    private static function emails(): array
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
