<?php

declare(strict_types=1);

use App\Mail\Templates\EmailBrand;
use App\Mail\Templates\RenderedEmail;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Modules\Reminders\Enums\ReminderChannel;
use Modules\Reminders\Enums\ReminderStatus;
use Modules\Reminders\Enums\SkipReason;
use Modules\Reminders\Models\Reminder;
use Modules\Reminders\Notifications\ReminderNotice;
use Modules\Reminders\Reminders\ReminderClock;
use Modules\Reminders\Reminders\ReminderPlanner;
use Modules\Reminders\Reminders\ReminderSender;

require_once __DIR__.'/reminder-world.php';

/*
 * P05-01 acceptance: "time-travel tests prove exact send days and zero
 * duplicates across reruns" (ARCHITECTURE.md § 6.7;
 * docs/plans/P05-subtasks.md defaults 1–7). Every day is lived as
 * production lives it (liveReminderDay: bills generated and flagged by
 * their scheduled jobs, then the hourly `reminders-dispatch` job before
 * the 08:00 send time, after it, and twice more), so "once" and "on that
 * day" are counted where the tenant would see them.
 */

beforeEach(function () {
    reminderWorld();
});

describe('acceptance: exact send days and zero duplicates across reruns', function () {
    it('sends the annual rent ladder and then the overdue ladder on their exact days, each once, however often the job runs', function () {
        activeLease($this->acme, $this->owner, $this->flat3b, $this->kemiAtAcme);

        $sent = liveReminderDays('2026-11-25', '2027-01-20');

        $kemi = fn (string $rung): array => [$rung.' email '.RM_KEMI, $rung.' in_app '.RM_KEMI];
        expect($sent)->toBe([
            '2026-12-02' => $kemi('rent_due T-30'),
            '2026-12-18' => $kemi('rent_due T-14'),
            '2026-12-25' => $kemi('rent_due T-7'),
            '2026-12-29' => $kemi('rent_due T-3'),
            '2026-12-31' => $kemi('rent_due T-1'),
            '2027-01-01' => $kemi('rent_due T-0'),
            '2027-01-02' => $kemi('bill_overdue +1'),
            '2027-01-08' => $kemi('bill_overdue +7'),
            '2027-01-15' => $kemi('bill_overdue +14'),
        ]);

        // Where Kemi sees them: nine emails and nine in-app notices, nothing pending.
        expect(app('mailer')->getSymfonyTransport()->messages())->toHaveCount(9)
            ->and(DB::table('notifications')->where('notifiable_id', $this->kemi->id)->count())->toBe(9)
            ->and(remindersOf($this->acme)->where('status', '!=', ReminderStatus::Sent))->toBeEmpty();

        // T−30 and T−14 came before the bill (issued on T−7, the lead window): from the lease.
        $rows = remindersOf($this->acme);
        expect($rows->where('offset_days', -30)->pluck('remindable_type')->unique()->all())->toBe(['lease'])
            ->and($rows->where('offset_days', -7)->pluck('remindable_type')->unique()->all())->toBe(['bill']);
    });

    it('plans and sends nothing twice when the job runs again, twice at once, or a notification is retried', function () {
        activeLease($this->acme, $this->owner, $this->flat3b, $this->kemiAtAcme);
        liveReminderDay('2026-12-25');
        $before = remindersOf($this->acme)->map(fn (Reminder $r): array => [$r->id, $r->dedupe_key, $r->status->value, (string) $r->sent_at])->all();
        $mails = count(reminderMails());

        // The job again, many times in the day, and the planner and sender straight after each other.
        foreach (['10:35', '13:35', '18:35'] as $time) {
            $this->travelTo('2026-12-25 '.$time.':00');
            runReminders();
            runReminders();
        }

        inOrganization($this->acme, function () {
            $today = ReminderClock::today();
            $planner = app(ReminderPlanner::class);
            $sender = app(ReminderSender::class);

            expect($planner->plan($today, fn () => false))->toBeTrue()
                ->and($planner->plan($today, fn () => false))->toBeTrue()
                ->and($sender->send(now()->toImmutable(), $today, fn () => false))->toBeTrue();
        });

        // A notification job retried after it went sends nothing more.
        $reminder = remindersOf($this->acme)->firstWhere('channel', ReminderChannel::Email);
        $again = new RenderedEmail('Again', '<p>Again</p>', 'Again', 'Open');
        $brand = new EmailBrand('Adeyemi Realty Ltd', 'Adeyemi Realty Ltd via GoProperty', '#0E6B5C', 'GoProperty');
        $notice = new ReminderNotice($reminder->id, 'rent_due', $this->acme->id, $again, $brand, null, 'mail');
        $notice->id = $reminder->notification_id;
        Notification::route('mail', RM_KEMI)->notify($notice);

        $inApp = remindersOf($this->acme)->firstWhere('channel', ReminderChannel::InApp);
        $retried = new ReminderNotice($inApp->id, 'rent_due', $this->acme->id, $again, $brand, null, 'database');
        $retried->id = $inApp->notification_id;
        $this->kemi->notify($retried);

        expect(remindersOf($this->acme)->map(fn (Reminder $r): array => [$r->id, $r->dedupe_key, $r->status->value, (string) $r->sent_at])->all())->toBe($before)
            ->and(reminderMails())->toHaveCount($mails)
            ->and(DB::table('notifications')->where('notifiable_id', $this->kemi->id)->count())->toBe(1);
    });

    it('uses only the rungs that fit the cycle: monthly from T−14, weekly from T−3, none at all for daily rent', function () {
        monthlyLease($this->acme, $this->owner, $this->oakUnit, $this->femi);
        activeLease($this->acme, $this->owner, $this->oakUnit2, $this->femi, ['billingCycle' => 'weekly', 'startDate' => '2027-01-04', 'endDate' => '2027-03-28', 'rent' => '30,000', 'deposit' => '0']);
        activeLease($this->acme, $this->owner, $this->flat3b, $this->kemiAtAcme, ['billingCycle' => 'daily', 'startDate' => '2027-01-01', 'endDate' => '2027-01-31', 'rent' => '5,000', 'deposit' => '0']);

        $sent = liveReminderDays('2026-11-25', '2027-01-06');

        $femi = fn (string $rung): string => $rung.' email '.RM_FEMI;
        expect($sent)->toBe([
            // Monthly, due 1 January: no T−30 (more than half a month).
            '2026-12-18' => [$femi('rent_due T-14')],
            '2026-12-25' => [$femi('rent_due T-7')],
            '2026-12-29' => [$femi('rent_due T-3')],
            '2026-12-31' => [$femi('rent_due T-1')],
            // Monthly T−0, and weekly (due Monday 4 January) T−3; Kemi's
            // one-month lease ends in 30 days (its T−60 fell before it started).
            '2027-01-01' => ['lease_renewal T-30 email '.RM_BOLA, 'lease_renewal T-30 in_app '.RM_BOLA, $femi('rent_due T-0'), $femi('rent_due T-3')],
            '2027-01-02' => [$femi('bill_overdue +1')],
            '2027-01-03' => [$femi('rent_due T-1')],
            '2027-01-04' => [$femi('rent_due T-0')],
            '2027-01-05' => [$femi('bill_overdue +1')],
        ])
            // Kemi's daily rent: bills, but never a reminder.
            ->and(billsOf($this->acme)->where('tenant_profile_id', $this->kemiAtAcme->id))->not->toBeEmpty()
            ->and(remindersOf($this->acme)->where('recipient_tenant_profile_id', $this->kemiAtAcme->id))->toBeEmpty();
    });

    it('does not catch up a day the job never ran, and records a planned reminder whose day ended unsent as missed', function () {
        activeLease($this->acme, $this->owner, $this->flat3b, $this->kemiAtAcme);
        liveReminderDays('2026-12-23', '2026-12-24');

        // 25 December: bills are generated, the reminder is planned before
        // 08:00, and then the job does not run again that day.
        $this->travelTo('2026-12-25 00:10:00');
        runBillGeneration();
        $this->travelTo('2026-12-25 06:35:00');
        runReminders();
        expect(remindersOf($this->acme)->where('offset_days', -7)->pluck('status')->all())->toBe([ReminderStatus::Pending, ReminderStatus::Pending]);

        // 26–28 December not lived at all; 29 December is T−3.
        $sent = liveReminderDays('2026-12-29', '2026-12-29');

        expect($sent)->toBe(['2026-12-29' => ['rent_due T-3 email '.RM_KEMI, 'rent_due T-3 in_app '.RM_KEMI]])
            ->and(remindersOf($this->acme)->where('offset_days', -7)->map(fn (Reminder $r) => [$r->status, $r->skip_reason])->values()->all())
            ->toBe([[ReminderStatus::Skipped, SkipReason::Missed], [ReminderStatus::Skipped, SkipReason::Missed]])
            ->and(reminderMails())->toHaveCount(1);
    });
});

describe('checked again before sending', function () {
    it('stops reminding once the bill is paid in full, also between planning and sending, and says what is still owed after a part payment', function () {
        activeLease($this->acme, $this->owner, $this->flat3b, $this->kemiAtAcme);
        liveReminderDays('2026-12-24', '2026-12-25');
        $bill = billsOf($this->acme)->sole();

        // Part paid on 27 December: T−3 says what is still owed.
        $this->travelTo('2026-12-27 10:00:00');
        confirmedPayment($this->acme, $this->owner, $this->kemiAtAcme, '500,000', [], [$bill->id => '500,000']);
        app('mailer')->getSymfonyTransport()->flush();
        liveReminderDay('2026-12-29');

        [$mail] = reminderMails();
        expect($mail[1])->toContain('₦1,000,000')
            ->and($mail[2])->toContain('Amount still owed: ₦1,000,000')
            ->and($mail[2])->toContain($bill->payment_reference);

        // 31 December: planned at 07:35, paid at 08:00, not sent at 09:35.
        $this->travelTo('2026-12-31 06:35:00');
        runReminders();
        $this->travelTo('2026-12-31 07:00:00');
        confirmedPayment($this->acme, $this->owner, $this->kemiAtAcme, '1,000,000', [], [$bill->id => '1,000,000']);
        app('mailer')->getSymfonyTransport()->flush();
        $this->travelTo('2026-12-31 08:35:00');
        runReminders();

        $sent = liveReminderDays('2027-01-01', '2027-01-20');

        expect(reminderMails())->toBe([])
            ->and(remindersOf($this->acme)->where('offset_days', -1)->map(fn (Reminder $r) => [$r->status, $r->skip_reason])->values()->all())
            ->toBe([[ReminderStatus::Skipped, SkipReason::Settled], [ReminderStatus::Skipped, SkipReason::Settled]])
            ->and(array_keys($sent))->toBe(['2026-12-25', '2026-12-29'])
            ->and(remindersOf($this->acme)->where('send_on', '>=', CarbonImmutable::parse('2027-01-01')))->toBeEmpty();
    });

    it('uses the bill for a reminder planned from the lease once the bill is issued before the send time', function () {
        setLeadDays($this->acme, 14);
        activeLease($this->acme, $this->owner, $this->flat3b, $this->kemiAtAcme);

        // 18 December (T−14) at 00:35 Lagos: planned from the lease; the bill comes at 01:10.
        $this->travelTo('2026-12-17 23:35:00');
        runReminders();
        expect(remindersOf($this->acme)->pluck('remindable_type')->unique()->all())->toBe(['lease']);
        liveReminderDay('2026-12-18');

        $bill = billsOf($this->acme)->sole();
        [$mail] = reminderMails();
        expect($mail[0])->toBe(RM_KEMI)
            ->and($mail[2])->toContain($bill->payment_reference)
            ->and($mail[2])->toContain('invoice '.$bill->number);
    });
});

describe('who hears', function () {
    it('tells the tenant in the agency\'s name, with the amount, the payment reference and their link', function () {
        activeLease($this->acme, $this->owner, $this->oakUnit, $this->femi);
        activeLease($this->acme, $this->owner, $this->flat3b, $this->kemiAtAcme);
        liveReminderDays('2026-12-24', '2026-12-25');

        $mails = collect(app('mailer')->getSymfonyTransport()->messages())->keyBy(fn ($m) => $m->getOriginalMessage()->getTo()[0]->getAddress());
        $femiBill = billsOf($this->acme)->firstWhere('tenant_profile_id', $this->femi->id);
        $kemiBill = billsOf($this->acme)->firstWhere('tenant_profile_id', $this->kemiAtAcme->id);
        $femiMail = $mails[RM_FEMI]->getOriginalMessage();

        expect($femiMail->getFrom()[0]->getName())->toBe('Adeyemi Realty Ltd via GoProperty')
            ->and($femiMail->getSubject())->toBe('Rent of ₦1,500,000 due in 7 days, on 1 January 2027: Unit 1, Oak Lodge')
            ->and((string) $femiMail->getTextBody())->toContain('Hello Femi Okon,')
            ->and((string) $femiMail->getTextBody())->toContain('Your rent for Unit 1, Oak Lodge (1 January 2027 to 31 December 2027) is due in 7 days, on 1 January 2027.')
            ->and((string) $femiMail->getTextBody())->toContain('Put the payment reference '.$femiBill->payment_reference.' in your transfer narration')
            // No portal account: the public pay page.
            ->and((string) $femiMail->getTextBody())->toContain(route('pay.show', ['reference' => $femiBill->payment_reference]))
            // A portal account: the portal's bill page.
            ->and((string) $mails[RM_KEMI]->getOriginalMessage()->getTextBody())->toContain(route('portal.bills.show', ['bill' => $kemiBill->id]));
    });

    it('never reminds a phone-only tenant, an archived tenant, or an imported tenant before the agency invites them', function () {
        activeLease($this->acme, $this->owner, $this->flat4c, $this->phoneOnly);
        activeLease($this->acme, $this->owner, $this->oakUnit, $this->femi);
        inOrganization($this->acme, fn () => DB::table('tenant_profiles')->where('id', $this->femi->id)->update(['import_batch_id' => 999]));

        liveReminderDays('2026-12-01', '2026-12-02');
        expect(remindersOf($this->acme))->toBeEmpty();

        // Invited (by link): reminders from the next rung on.
        inviteByLink($this->owner, $this->femi);
        $sent = liveReminderDays('2026-12-17', '2026-12-18');

        expect($sent)->toBe(['2026-12-18' => ['rent_due T-14 email '.RM_FEMI]]);
    });

    it('keeps each organization\'s reminders to its own tenants, in its own name', function () {
        activeLease($this->acme, $this->owner, $this->flat3b, $this->kemiAtAcme);
        activeLease($this->other, $this->otherOwner, $this->foreignUnit, $this->foreignTenant);

        liveReminderDays('2026-12-02', '2026-12-02');

        expect(sentReminders($this->acme))->toBe(['2026-12-02' => ['rent_due T-30 email '.RM_KEMI, 'rent_due T-30 in_app '.RM_KEMI]])
            ->and(sentReminders($this->other))->toBe(['2026-12-02' => ['rent_due T-30 email foreign.tenant@example.com']]);

        $mails = collect(reminderMails())->keyBy(0);
        expect(app('mailer')->getSymfonyTransport()->messages()->first(fn ($m) => $m->getOriginalMessage()->getTo()[0]->getAddress() === 'foreign.tenant@example.com')->getOriginalMessage()->getFrom()[0]->getName())
            ->toBe('Bello Homes Ltd via GoProperty')
            ->and($mails[RM_KEMI][2])->not->toContain('Bello');
    });
});
