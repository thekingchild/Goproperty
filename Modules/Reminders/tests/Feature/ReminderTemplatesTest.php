<?php

declare(strict_types=1);

use App\Mail\Suppression\SuppressionReason;
use App\Mail\Suppression\Suppressions;
use App\Mail\Templates\EmailTemplates;
use App\Mail\Templates\TemplateContent;
use App\Models\EmailTemplate;
use Database\Seeders\EmailTemplatesSeeder;
use Illuminate\Support\Facades\DB;
use Modules\Reminders\Enums\ReminderChannel;
use Modules\Reminders\Enums\ReminderStatus;
use Modules\Reminders\Enums\SkipReason;
use Modules\Reminders\Reminders\ReminderTemplates;

require_once __DIR__.'/reminder-world.php';

/*
 * P05-04 with P05-01: reminders are worded by GoProperty's email templates
 * (docs/plans/P05-subtasks.md default 16), in the agency's branding, and
 * never go to a suppressed address (acceptance: "suppressed addresses are
 * skipped").
 */

beforeEach(function () {
    reminderWorld();
    $this->seed(EmailTemplatesSeeder::class);
});

it('seeds one template per reminder type and kind of rung, with P05-01\'s wording', function () {
    expect(EmailTemplate::query()->where('key', 'like', 'reminder.%')->orderBy('key')->pluck('key')->all())->toBe([
        ReminderTemplates::BILL_DUE,
        ReminderTemplates::BILL_OVERDUE,
        ReminderTemplates::LEASE_ENDING,
        ReminderTemplates::LEASE_ENDING_AUTO_RENEW,
        ReminderTemplates::RENT_DUE,
        ReminderTemplates::RENT_UPCOMING,
    ])->and(EmailTemplate::query()->where('key', ReminderTemplates::RENT_DUE)->sole()->currentVersion->version)->toBe(1);
});

it('sends what the content manager wrote, in the agency\'s branding, by email and in the app', function () {
    $chika = staffNamed('Chika', ['content-manager']);
    $template = EmailTemplate::query()->where('key', ReminderTemplates::RENT_DUE)->sole();
    app(EmailTemplates::class)->save($template, new TemplateContent(
        'Reminder: {{ bill.owed }} for {{ unit.place }} is due {{ bill.due_when }}',
        "Dear {{ recipient.name }},\n\nPlease pay **{{ bill.owed }}** with reference {{ bill.payment_reference }}. {{ tenant.secret }} {{ \$bill->tenant->user->password }}\n\n{{ agency.name }}",
        'Pay now',
    ), $chika);

    activeLease($this->acme, $this->owner, $this->flat3b, $this->kemiAtAcme);
    liveReminderDays('2026-12-24', '2026-12-25');

    $bill = billsOf($this->acme)->sole();
    $message = collect(app('mailer')->getSymfonyTransport()->messages())->sole()->getOriginalMessage();
    $html = (string) $message->getHtmlBody();

    expect($message->getSubject())->toBe('Reminder: ₦1,500,000 for Flat 3B, Palm Court is due in 7 days, on 1 January 2027')
        ->and($message->getFrom()[0]->getName())->toBe('Adeyemi Realty Ltd via GoProperty')
        ->and((string) $message->getTextBody())->toContain('Dear Kemi Adebayo,')
        ->and((string) $message->getTextBody())->toContain('Pay now: '.route('portal.bills.show', ['bill' => $bill->id]))
        ->and($html)->toContain('data-test="branded-email"')
        ->and($html)->toContain('Please pay <strong>₦1,500,000</strong> with reference '.$bill->payment_reference.'.')
        ->and($html)->toContain('{{ tenant.secret }} {{ $bill-&gt;tenant-&gt;user-&gt;password }}')
        ->and($html)->toContain('>Pay now</a>')
        ->and($html)->toContain('Sent with GoProperty on behalf of Adeyemi Realty Ltd.');

    $inApp = json_decode((string) DB::table('notifications')->where('notifiable_id', $this->kemi->id)->value('data'), true);
    expect($inApp['message'])->toBe('Reminder: ₦1,500,000 for Flat 3B, Palm Court is due in 7 days, on 1 January 2027');
});

it('skips the email to a suppressed address, recorded as suppressed, and still tells the tenant in the app', function () {
    activeLease($this->acme, $this->owner, $this->flat3b, $this->kemiAtAcme);
    activeLease($this->acme, $this->owner, $this->oakUnit, $this->femi);
    app(Suppressions::class)->suppress(RM_KEMI, SuppressionReason::Bounce, 'postmark');

    $sent = liveReminderDays('2026-12-24', '2026-12-25');

    expect($sent)->toBe(['2026-12-25' => ['rent_due T-7 email '.RM_FEMI, 'rent_due T-7 in_app '.RM_KEMI]])
        ->and(collect(app('mailer')->getSymfonyTransport()->messages())->map(fn ($m) => $m->getOriginalMessage()->getTo()[0]->getAddress())->all())->toBe([RM_FEMI]);

    $kemiEmail = remindersOf($this->acme)->where('recipient_email', RM_KEMI)->firstWhere('channel', ReminderChannel::Email);
    expect($kemiEmail->status)->toBe(ReminderStatus::Skipped)
        ->and($kemiEmail->skip_reason)->toBe(SkipReason::Suppressed);
});

it('skips a renewal alert to a staff member whose address is suppressed', function () {
    activeLease($this->acme, $this->owner, $this->flat3b, $this->kemiAtAcme);
    app(Suppressions::class)->suppress(RM_BOLA, SuppressionReason::Complaint, 'mailgun');

    liveReminderDays('2027-11-01', '2027-11-01');

    $alerts = remindersOf($this->acme)->where('type.value', 'lease_renewal');
    expect($alerts->firstWhere('channel', ReminderChannel::Email)?->skip_reason)->toBe(SkipReason::Suppressed)
        ->and($alerts->firstWhere('channel', ReminderChannel::InApp)?->status)->toBe(ReminderStatus::Sent)
        ->and(collect(reminderMails())->where(0, RM_BOLA))->toBeEmpty();
});
