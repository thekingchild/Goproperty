<?php

declare(strict_types=1);

use App\Models\NotificationDelivery;
use App\Models\User;
use App\Notifications\Preferences\NotificationPreferences;
use App\Sms\DeliveryStatus;
use App\Sms\SmsSender;
use App\Sms\SmsText;
use Illuminate\Support\Facades\DB;
use Modules\Billing\Models\Bill;
use Modules\Reminders\Enums\ReminderChannel;
use Modules\Reminders\Enums\ReminderStatus;
use Modules\Reminders\Enums\SkipReason;

require_once __DIR__.'/reminder-world.php';
require_once __DIR__.'/../../../Billing/tests/Feature/utility-helpers.php';

/*
 * P05-02: SMS reminders (docs/plans/P05-subtasks.md defaults 14–15;
 * ARCHITECTURE.md § 6.7). Acceptance: "unverified numbers never texted";
 * SMS only on its own rungs, in GoProperty's SMS wording, once each, and
 * never on a channel the tenant turned off.
 */

beforeEach(function () {
    reminderWorld();
});

function verifyPhone(User $user): void
{
    $user->forceFill(['phone_verified_at' => now(), 'phone_verification_method' => 'whatsapp_link'])->save();
}

/** @return list<array{to: string, text: string, id: string}> */
function textsSent(): array
{
    return app(SmsSender::class)->sent();
}

/** Each day's SMS reminders only. */
function smsDays(array $sent): array
{
    return array_filter(array_map(fn (array $lines): array => array_values(array_filter($lines, fn (string $line): bool => str_contains($line, ' sms '))), $sent));
}

describe('acceptance: unverified numbers are never texted', function () {
    it('texts nobody whose number is not verified: an unverified account, a tenant with no account, a phone-only tenant', function () {
        activeLease($this->acme, $this->owner, $this->flat3b, $this->kemiAtAcme);   // account, number not verified
        activeLease($this->acme, $this->owner, $this->oakUnit, $this->femi);        // no account
        activeLease($this->acme, $this->owner, $this->flat4c, $this->phoneOnly);    // no account, no email

        $sent = liveReminderDays('2026-12-24', '2027-01-09');

        expect(smsDays($sent))->toBe([])
            ->and(remindersOf($this->acme)->where('channel', ReminderChannel::Sms))->toBeEmpty()
            ->and(textsSent())->toBe([])
            ->and(DB::table('notification_deliveries')->count())->toBe(0);
    });

    it('stops texting a number that is no longer verified, or was changed after the reminder was planned', function () {
        activeLease($this->acme, $this->owner, $this->flat3b, $this->kemiAtAcme);
        verifyPhone($this->kemi);
        liveReminderDays('2026-12-24', '2026-12-25');
        expect(textsSent())->toHaveCount(1);

        // Planned for T−1 at midnight, then the number changes before 08:00.
        $this->travelTo('2026-12-31 00:40:00');
        runReminders();
        $this->kemi->forceFill(['phone' => '+2348039998888', 'phone_verified_at' => null])->save();
        foreach (['08:35', '09:35'] as $time) {
            $this->travelTo('2026-12-31 '.$time.':00');
            runReminders();
        }

        $t1 = remindersOf($this->acme)->where('channel', ReminderChannel::Sms)->firstWhere('offset_days', -1);
        expect($t1->status)->toBe(ReminderStatus::Skipped)
            ->and($t1->skip_reason)->toBe(SkipReason::Unreachable)
            ->and(textsSent())->toHaveCount(1)
            ->and(collect(textsSent())->pluck('to')->all())->toBe(['+2348031112222']);
    });
});

it('texts a verified number on rent T−7 and T−1 and overdue +1 and +7 only, once each, while email and the app get every rung', function () {
    activeLease($this->acme, $this->owner, $this->flat3b, $this->kemiAtAcme);
    verifyPhone($this->kemi);

    $sent = liveReminderDays('2026-11-25', '2027-01-20');

    expect(smsDays($sent))->toBe([
        '2026-12-25' => ['rent_due T-7 sms '.RM_KEMI],
        '2026-12-31' => ['rent_due T-1 sms '.RM_KEMI],
        '2027-01-02' => ['bill_overdue +1 sms '.RM_KEMI],
        '2027-01-08' => ['bill_overdue +7 sms '.RM_KEMI],
    ])
        ->and(textsSent())->toHaveCount(4)
        ->and(app('mailer')->getSymfonyTransport()->messages())->toHaveCount(9)
        ->and(DB::table('notification_deliveries')->count())->toBe(4);

    $bill = billsOf($this->acme)->sole();
    $texts = collect(textsSent())->pluck('text')->all();
    expect($texts[0])->toBe('Adeyemi Realty Ltd: rent of NGN 1,500,000 is due in 7 days (1 Jan). Pay to ref '.$bill->payment_reference.': '.route('pay.show', ['reference' => $bill->payment_reference]))
        ->and($texts[1])->toContain('is due tomorrow (1 Jan)')
        ->and($texts[2])->toBe('Adeyemi Realty Ltd: NGN 1,500,000 is overdue (due 1 Jan). Ref '.$bill->payment_reference.': '.route('pay.show', ['reference' => $bill->payment_reference]));

    foreach ($texts as $text) {
        expect(strlen($text))->toBeLessThanOrEqual(SmsText::PAGE)->and(SmsText::outside($text))->toBe([]);
    }

    // Each SMS reminder went once and says so; its delivery was checked: delivered.
    $smsRows = remindersOf($this->acme)->where('channel', ReminderChannel::Sms);
    $deliveries = inOrganization($this->acme, fn () => NotificationDelivery::query()->orderBy('id')->get());
    expect($smsRows->every(fn ($r) => $r->status === ReminderStatus::Sent && $r->delivered_at !== null && $r->recipient_phone === '+2348031112222'))->toBeTrue()
        ->and($deliveries->pluck('source_id')->all())->toBe($smsRows->pluck('id')->values()->all())
        ->and($deliveries->pluck('status')->unique()->all())->toBe([DeliveryStatus::Delivered])
        ->and($deliveries->pluck('notification_type')->unique()->values()->all())->toBe(['reminder.rent_due', 'reminder.bill_overdue']);
});

it('texts a utility bill on its due day only (utility T−0), and never on its T−3 rung', function () {
    activeLease($this->acme, $this->owner, $this->flat3b, $this->kemiAtAcme);
    verifyPhone($this->kemi);
    $levy = utilityOn($this->acme, $this->owner, $this->flat3b, utilityTypeIn($this->acme, $this->owner));

    $sent = liveReminderDays('2026-12-20', '2027-01-05');

    $bill = inOrganization($this->acme, fn () => Bill::query()->where('unit_utility_setting_id', $levy->id)->orderBy('period_start')->firstOrFail());
    $utilitySms = remindersOf($this->acme)->where('channel', ReminderChannel::Sms)->where('type.value', 'utility_due');
    $utilityEmails = remindersOf($this->acme)->where('channel', ReminderChannel::Email)->where('type.value', 'utility_due');

    expect($utilitySms->map(fn ($r) => [$r->offset_days, $r->send_on->toDateString()])->values()->all())->toBe([[0, $bill->due_date->toDateString()]])
        ->and($utilityEmails->pluck('offset_days')->sort()->values()->all())->toBe([-3, 0])
        ->and(collect(textsSent())->pluck('text')->first(fn (string $text): bool => str_contains($text, 'Utility of')))
        ->toBe('Adeyemi Realty Ltd: Utility of NGN 25,000 due today ('.$bill->due_date->format('j M').'). Ref '.$bill->payment_reference.': '.route('pay.show', ['reference' => $bill->payment_reference]));
});

it('plans and sends nothing twice when the job runs again and again', function () {
    activeLease($this->acme, $this->owner, $this->flat3b, $this->kemiAtAcme);
    verifyPhone($this->kemi);

    liveReminderDays('2026-12-24', '2026-12-25');
    foreach (['10:35', '13:35', '18:35'] as $time) {
        $this->travelTo('2026-12-25 '.$time.':00');
        runReminders();
        runReminders();
    }

    expect(textsSent())->toHaveCount(1)
        ->and(DB::table('notification_deliveries')->count())->toBe(1)
        ->and(remindersOf($this->acme)->where('channel', ReminderChannel::Sms))->toHaveCount(1);
});

describe('per-user channel preferences', function () {
    it('plans no SMS or no email for a tenant who turned that channel off, and still tells them in the app', function () {
        activeLease($this->acme, $this->owner, $this->flat3b, $this->kemiAtAcme);
        verifyPhone($this->kemi);
        app(NotificationPreferences::class)->set($this->kemi, 'reminders', ['sms' => false, 'email' => false]);

        $sent = liveReminderDays('2026-12-24', '2026-12-25');

        expect($sent)->toBe(['2026-12-25' => ['rent_due T-7 in_app '.RM_KEMI]])
            ->and(textsSent())->toBe([])
            ->and(app('mailer')->getSymfonyTransport()->messages())->toHaveCount(0);
    });

    it('skips a reminder planned before the tenant turned its channel off', function () {
        activeLease($this->acme, $this->owner, $this->flat3b, $this->kemiAtAcme);
        verifyPhone($this->kemi);
        liveReminderDays('2026-12-24', '2026-12-24');

        $this->travelTo('2026-12-25 00:40:00');
        runReminders();
        app(NotificationPreferences::class)->set($this->kemi->fresh(), 'reminders', ['sms' => false]);
        $this->travelTo('2026-12-25 08:35:00');
        runReminders();

        $sms = remindersOf($this->acme)->firstWhere('channel', ReminderChannel::Sms);
        expect($sms->status)->toBe(ReminderStatus::Skipped)
            ->and($sms->skip_reason)->toBe(SkipReason::OptedOut)
            ->and(remindersOf($this->acme)->firstWhere('channel', ReminderChannel::Email)->status)->toBe(ReminderStatus::Sent)
            ->and(textsSent())->toBe([]);
    });

    it('lets staff turn off renewal alert emails, and never texts renewal alerts', function () {
        activeLease($this->acme, $this->owner, $this->flat3b, $this->kemiAtAcme);
        $this->agent->forceFill(['phone' => '+2348035554444', 'phone_verified_at' => now()])->save();
        app(NotificationPreferences::class)->set($this->agent, 'reminders', ['email' => false]);

        liveReminderDays('2027-11-01', '2027-11-01');

        $alerts = remindersOf($this->acme)->where('type.value', 'lease_renewal');
        expect($alerts->pluck('channel')->all())->toBe([ReminderChannel::InApp])
            ->and(textsSent())->toBe([]);
    });
});
